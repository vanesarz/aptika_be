<?php

namespace App\Http\Controllers;

use App\Models\Magang;
use App\Models\User;
use App\Services\TaskManagement\NotificationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class MagangController extends Controller
{
    public function __construct(private NotificationService $notificationService)
    {
    }

    /**
     * INDEX
     */
    private function getCvUrl($cvPath)
    {
        if (! $cvPath) {
            return null;
        }
        if (str_starts_with($cvPath, 'http://') || str_starts_with($cvPath, 'https://')) {
            return $cvPath;
        }
        $cleanPath = ltrim($cvPath, '/');
        if (str_starts_with($cleanPath, 'storage/')) {
            $cleanPath = substr($cleanPath, 8);
        }

        return url('api/storage/'.$cleanPath);
    }

    /**
     * SERVE FILE VIA API ROUTE (Bypasses webserver static file 404 blocks)
     */
    public function serveFile($path)
    {
        $cleanPath = ltrim(urldecode($path), '/');
        if (str_starts_with($cleanPath, 'storage/')) {
            $cleanPath = substr($cleanPath, 8);
        }

        // Additional registration files must only be served through the authorized attachment route.
        $segments = explode('/', str_replace('\\', '/', $cleanPath));
        abort_if(str_contains($cleanPath, "\0") || in_array('..', $segments, true)
            || strtolower($segments[0] ?? '') === 'private', 404);

        $fullPath = storage_path('app/public/'.$cleanPath);

        if (! file_exists($fullPath)) {
            $altPath = storage_path('app/'.$cleanPath);
            if (file_exists($altPath)) {
                $fullPath = $altPath;
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'File tidak ditemukan di server disk.',
                ], 404);
            }
        }

        return response()->file($fullPath);
    }

    public function index()
    {
        $data = Magang::with(['bidang', 'dokumens', 'mentor:id,name'])->latest()->orderByDesc('id')->get()->map(fn ($item) => $this->serializeMagang($item));

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * STORE
     */
    public function store(Request $request)
    {
        // Jika user login dan bidang_id tidak dikirim, otomatis isi dengan bidang_id user
        if (auth()->check() && ! $request->filled('bidang_id') && auth()->user()->bidang_id) {
            $request->merge(['bidang_id' => auth()->user()->bidang_id]);
        }

        $documentRules = [];
        foreach (config('magang.dokumen_pendaftaran') as $key => $spec) {
            $documentRules[$key] = ($spec['wajib'] ? 'required' : 'nullable').'|file|mimes:pdf|max:5120';
        }
        $validated = $request->validate([
            ...$documentRules,
            'pas_foto' => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
            'foto_latar' => 'nullable|in:merah,biru',
            'username' => [
                'bail', 'required', 'string', 'min:3', 'max:64', 'regex:/^[a-z0-9][a-z0-9._-]*$/',
                function (string $attribute, string $username, \Closure $fail): void {
                    $email = $username.'@magang.com';
                    if (User::where('email', $email)->exists() || Magang::withoutGlobalScopes()->where('email_login', $email)->exists()) {
                        $fail('Nama akun magang sudah digunakan. Pilih nama lain.');
                    }
                },
            ],
            'nim' => 'required|string|max:100',
            'jurusan' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'telepon' => 'required|string|max:30',
            'alamat' => 'required|string|max:2000',
            'memiliki_laptop' => 'required|boolean',
            'nama' => 'required|string|max:255',
            'nama_kampus' => 'required|string|max:255',
            'bidang_id' => auth()->check() ? 'nullable|exists:bidangs,id' : 'required|exists:bidangs,id',

            'tgl_mulai_magang' => 'required|date',
            'tgl_selesai_magang' => 'required|date|after_or_equal:tgl_mulai_magang',

            'cv_magang' => 'required|file|mimes:pdf|max:2048',
            'nda_file' => 'nullable|file|mimes:pdf|max:10240',

            'sertifikat' => 'nullable|in:Sudah menerima,Belum menerima',

            'keterangan' => 'nullable|string',
        ]);

        $loginEmail = $validated['username'].'@magang.com';
        unset($validated['username']);
        $attachments = [];
        foreach (config('magang.dokumen_pendaftaran') as $key => $spec) {
            unset($validated[$key]);
            if ($request->hasFile($key)) {
                $file = $request->file($key);
                $attachments[$key] = ['path' => $file->store('magang-pendaftaran', 'local'), 'nama' => $file->getClientOriginalName(), 'label' => $spec['label']];
            }
        }
        if ($request->hasFile('pas_foto')) {
            $validated['pas_foto'] = $request->file('pas_foto')->store('pas-foto-magang', 'local');
        } else {
            unset($validated['foto_latar']);
        }
        $validated['email_login'] = $loginEmail;
        $validated['lampiran_pendaftaran'] = $attachments;
        if (empty($validated['bidang_id']) && auth()->check() && auth()->user()->bidang_id) {
            $validated['bidang_id'] = auth()->user()->bidang_id;
        }

        if ($request->hasFile('cv_magang')) {
            $validated['cv_magang'] = $request
                ->file('cv_magang')
                ->store('cv-magang', 'public');
        }

        unset($validated['nda_file'], $validated['sertifikat']);

        $validated['sertifikat'] = $validated['sertifikat'] ?? 'Belum menerima';

        $magang = Magang::create($validated);
        $magang->load('bidang');
        $this->notificationService->notifyMagangNewApplicant($magang);

        return response()->json([
            'success' => true,
            'message' => 'Data pendaftaran magang berhasil dikirim.',
            'data' => ['id' => $magang->id, 'email_login' => $magang->email_login, 'status_seleksi' => 'menunggu', 'sertifikat' => $magang->sertifikat],
        ], 201);
    }

    /**
     * SHOW
     */
    public function show($id)
    {
        $magang = Magang::with(['bidang', 'dokumens'])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $this->serializeMagang($magang),
        ]);
    }

    /**
     * UPDATE
     */
    public function update(Request $request, $id)
    {

        return DB::transaction(function () use ($request, $id) {
            $magang = Magang::whereKey($id)->lockForUpdate()->firstOrFail();
            $this->authorizeStaff($request, $magang);
            abort_if($magang->disahkan_at, 422, 'Dokumen akhir sudah disahkan. Koreksi harus mengikuti prosedur dinas.');

            $validated = $request->validate([
                'nim' => 'sometimes|string|max:100',
                'jurusan' => 'sometimes|string|max:255',
                'email' => 'sometimes|email|max:255',
                'telepon' => 'sometimes|string|max:30',
                'alamat' => 'sometimes|string|max:2000',
                'memiliki_laptop' => 'sometimes|boolean',
                'nama' => 'required|string|max:255',
                'nama_kampus' => 'required|string|max:255',
                'bidang_id' => 'nullable|exists:bidangs,id',

                'tgl_mulai_magang' => 'required|date',
                'tgl_selesai_magang' => 'required|date|after_or_equal:tgl_mulai_magang',

                'cv_magang' => 'nullable|file|mimes:pdf,doc,docx,jpg,jpeg,png|max:2048',

                'sertifikat' => 'nullable|in:Sudah menerima,Belum menerima',

                'alasan_perubahan' => 'nullable|string|max:2000',
                'keterangan' => 'nullable|string',
            ]);

            $cvUpload = $validated['cv_magang'] ?? null;
            unset($validated['cv_magang']);

            if (! $request->user()->isAdminAptika()) {
                $validated['bidang_id'] = $magang->bidang_id;
            }
            $reason = $validated['alasan_perubahan'] ?? null;
            unset($validated['sertifikat'], $validated['alasan_perubahan']);
            $magang->fill($validated);
            $changed = array_keys($magang->getDirty());
            $documentFields = ['nama', 'nama_kampus', 'nim', 'jurusan', 'alamat', 'tgl_mulai_magang', 'tgl_selesai_magang', 'bidang_id'];
            if ($magang->status_seleksi !== 'menunggu' && array_intersect($changed, $documentFields)) {
                abort_if(blank($reason), 422, 'Tuliskan alasan koreksi identitas atau periode setelah seleksi.');
                abort_if($magang->dinilai_at, 422, 'Identitas dan periode pada penilaian yang tersimpan harus ditinjau sebelum dikoreksi.');
                abort_if(in_array('bidang_id', $changed), 422, 'Bidang tidak dapat dipindahkan setelah seleksi.');
                if (array_intersect($changed, ['tgl_mulai_magang', 'tgl_selesai_magang']) && $magang->mentor_id) {
                    User::whereKey($magang->mentor_id)->lockForUpdate()->firstOrFail();
                    $this->assertMentorCapacity($magang, $magang->mentor_id);
                }
                $magang->nda_validated_at = null;
                $magang->nda_validated_by = null;
                // Previous letters describe the old identity/period and need staff review.
                $magang->dokumens()->update(['status' => 'perlu_revisi']);
            }
            if ($cvUpload) {
                $magang->cv_magang = $cvUpload->store('cv-magang', 'public');
                $changed[] = 'cv_magang';
            }
            if ($changed) {
                $magang->riwayat_perubahan = [...($magang->riwayat_perubahan ?? []), [
                    'waktu' => now()->toIso8601String(), 'oleh' => $request->user()->id,
                    'kolom' => $changed, 'alasan' => $reason,
                ]];
            }
            $magang->save();

            return response()->json([
                'success' => true,
                'message' => 'Data berhasil diupdate.',
                'data' => $magang,
            ]);
        });
    }

    /**
     * UPLOAD NDA
     */
    public function uploadNda(Request $request, $id)
    {

        return DB::transaction(function () use ($request, $id) {
            $magang = Magang::whereKey($id)->lockForUpdate()->firstOrFail();

            if (! $request->user()->is_magang || (int) $magang->user_id !== (int) $request->user()->id) {
                $this->authorizeStaff($request, $magang);
            }
            abort_unless($magang->status_seleksi === 'diterima' && ! $magang->disahkan_at, 422, 'NDA hanya dapat diunggah setelah diterima dan sebelum dokumen akhir disahkan.');
            $request->validate([
                'nda_file' => 'required|file|mimes:pdf|max:10240',
            ]);

            if ($magang->nda_file && Storage::disk('public')->exists($magang->nda_file)) {
                Storage::disk('public')->delete($magang->nda_file);
            }

            $path = $request->file('nda_file')->store('nda-file', 'public');
            $magang->update(['nda_file' => $path, 'nda_validated_at' => null, 'nda_validated_by' => null]);

            return response()->json([
                'success' => true,
                'message' => 'File NDA berhasil diupload.',
                'data' => [
                    'nda_file' => $this->getCvUrl($path),
                ],
            ]);
        });
    }

    /**
     * DELETE
     */
    public function destroy(Request $request, $id)
    {
        $files = DB::transaction(function () use ($request, $id) {
            $magang = Magang::whereKey($id)->lockForUpdate()->firstOrFail();
            $this->authorizeStaff($request, $magang);

            // Delete only the linked participant account, never a staff account.
            $avatar = null;
            if ($magang->user_id) {
                $account = User::whereKey($magang->user_id)->where('is_magang', true)->lockForUpdate()->first();
                if ($account) {
                    $avatar = $account->avatar;
                    $account->tokens()->delete();
                    $account->delete();
                }
            }
            $files = [
                'public' => array_filter([
                    $avatar, $magang->cv_magang, $magang->nda_file, $magang->sertifikat_file,
                    ...$magang->dokumens()->pluck('file')->all(),
                ]),
                'local' => array_filter([$magang->pas_foto, ...array_column($magang->lampiran_pendaftaran ?? [], 'path')]),
            ];
            $magang->delete();

            return $files;
        });

        // Remove files only after the database transaction commits successfully.
        foreach ($files as $disk => $paths) {
            $paths = array_values(array_filter($paths, fn ($path) => is_string($path)
                && ! str_contains($path, '://') && ! in_array('..', explode('/', str_replace('\\', '/', $path)), true)));
            if ($paths) {
                try {
                    Storage::disk($disk)->delete($paths);
                } catch (\Throwable $exception) {
                    report($exception);
                }
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Data magang berhasil dihapus. Akun peserta terkait ikut dihapus.',
        ]);
    }

    private function pengingat(Magang $magang): ?string
    {
        if ($magang->status_seleksi !== 'diterima') {
            return null;
        }
        $pending = [];
        if (! $magang->dinilai_at) {
            $pending[] = 'penilaian';
        }
        if (! $magang->disahkan_at) {
            $pending[] = 'sertifikat';
        }
        if (! $magang->dokumens->contains(fn ($d) => $d->jenis === 'selesai' && $d->status === 'disahkan')) {
            $pending[] = 'surat selesai';
        }
        if ($magang->kampus_perlu_input && ! $magang->kampus_sudah_input) {
            $pending[] = 'input atau dokumen kampus';
        }
        if (! $pending) {
            return null;
        }
        $days = (int) Carbon::parse(now('Asia/Jakarta')->toDateString())
            ->diffInDays(Carbon::parse($magang->tgl_selesai_magang->toDateString()), false);
        $schedule = $magang->pengingat_hari ?? config('magang.pengingat_hari', [14, 7]);
        if ($days > 0 && (! $schedule || $days > max($schedule))) {
            return null;
        }
        $when = $days < 0 ? 'Lewat periode' : ($days === 0 ? 'Hari terakhir' : 'H-'.$days);

        return $when.': tindak lanjuti '.implode(', ', $pending);
    }

    private function authorizeStaff(Request $request, Magang $magang): void
    {
        $user = $request->user();
        abort_if($user?->is_magang, 403, 'Tindakan ini hanya untuk pengelola magang.');
        abort_unless($user && ($user->isAdminAptika() ||
            ((int) $user->bidang_id === (int) $magang->bidang_id &&
                ($user->role === 'admin' || ! $magang->mentor_id || (int) $magang->mentor_id === (int) $user->id))), 403,
            'Hanya admin bidang atau mentor peserta yang dapat melakukan tindakan ini.');
    }

    private function dapatMengaksesPenilaian(?User $user, Magang $magang): bool
    {
        if (! $user) {
            return false;
        }
        if ($user->is_magang) {
            return (int) $magang->user_id === (int) $user->id;
        }

        return $user->isAdminAptika() || ((int) $user->bidang_id === (int) $magang->bidang_id &&
            ($user->role === 'admin' || (int) $magang->mentor_id === (int) $user->id));
    }

    public function seleksi(Request $request, $id)
    {
        $data = $request->validate([
            'status_seleksi' => 'required|in:diterima,ditolak',
            'catatan_seleksi' => 'nullable|string|max:2000',
            'mentor_id' => 'nullable|integer|exists:users,id',
            'jurusan_sesuai' => 'exclude_unless:status_seleksi,diterima|required|accepted',
        ]);
        $magang = DB::transaction(function () use ($request, $id, $data) {
            $participant = Magang::findOrFail($id);
            $this->authorizeStaff($request, $participant);
            $mentorId = $data['mentor_id'] ?? $request->user()->id;
            $mentor = User::whereKey($mentorId)->lockForUpdate()->firstOrFail();
            $participant = Magang::whereKey($id)->lockForUpdate()->firstOrFail();
            $this->authorizeStaff($request, $participant);
            abort_unless($participant->status_seleksi === 'menunggu', 422, 'Pendaftar sudah diseleksi.');
            if ($data['status_seleksi'] === 'diterima') {
                abort_unless($mentor->is_active && ! $mentor->is_magang && (int) $mentor->bidang_id === (int) $participant->bidang_id, 422, 'Pilih mentor aktif pada bidang peserta.');
                foreach (['nim', 'jurusan', 'email', 'telepon', 'alamat'] as $field) {
                    abort_if(blank($participant->$field), 422, 'Lengkapi identitas peserta sebelum diterima.');
                }
                if ($participant->bidang?->code === 'APTIKA') {
                    abort_unless($participant->memiliki_laptop, 422, 'Peserta Aptika perlu memiliki laptop untuk penugasan.');
                }
                $this->assertMentorCapacity($participant, $mentorId);
                $data = [...$data, ...$this->buatAkunPeserta($participant)];
                $data['mentor_id'] = $mentorId;
                $data['jurusan_sesuai'] = true;
                $data['diterima_at'] = now();
                $data['nomor_nda'] = sprintf('DRAF-NDA-%s-%06d', now()->format('Y'), $participant->id);
            } else {
                unset($data['mentor_id'], $data['jurusan_sesuai']);
            }
            $participant->update($data);
            if ($participant->status_seleksi === 'diterima') {
                $participant->dokumens()->firstOrCreate(['jenis' => 'penerimaan'], ['status' => 'diproses', 'dicatat_oleh' => $request->user()->id]);
            }

            return $participant;
        });

        $warning = $magang->status_seleksi === 'diterima' ? $this->kirimPenerimaan($magang) : $this->kirimPenolakan($magang);
        if ($magang->status_seleksi === 'diterima') {
            $this->notificationService->notifyMagangMentorAssigned($magang, $request->user());
        }

        return response()->json(['success' => true, 'data' => $magang, 'warning' => $warning]);
    }

    public function kirimUlangPenerimaan(Request $request, $id)
    {
        $magang = Magang::findOrFail($id);
        $this->authorizeStaff($request, $magang);
        abort_unless($magang->status_seleksi === 'diterima' && $magang->user_id, 422, 'Peserta belum memiliki akun yang disetujui.');
        $warning = $this->kirimPenerimaan($magang);
        return response()->json(['success' => !$warning, 'warning' => $warning]);
    }

    public function kirimUlangPenolakan(Request $request, $id)
    {
        $magang = Magang::findOrFail($id);
        $this->authorizeStaff($request, $magang);
        abort_unless($magang->status_seleksi === 'ditolak', 422, 'Pendaftar belum dinyatakan ditolak.');
        $warning = $this->kirimPenolakan($magang);
        return response()->json(['success' => !$warning, 'warning' => $warning]);
    }

    private function kirimPenolakan(Magang $magang): ?string
    {
        if (in_array(config('mail.default'), ['log', 'array', 'null'], true)) {
            return 'Keputusan penolakan tersimpan, tetapi email belum dikirim. Konfigurasikan SMTP lalu kirim ulang pemberitahuan.';
        }
        try {
            $this->kirimEmailHasilSeleksi($magang);
            return null;
        } catch (\Throwable $e) {
            report($e);
            return 'Keputusan penolakan tersimpan, tetapi email gagal dikirim. Periksa konfigurasi email lalu kirim ulang pemberitahuan.';
        }
    }

    private function kirimPenerimaan(Magang $magang): ?string
    {
        if (in_array(config('mail.default'), ['log', 'array', 'null'], true)) {
            return 'Akun sudah dibuat, tetapi email belum dikirim. Konfigurasikan layanan email SMTP lalu kirim ulang pemberitahuan.';
        }
        if (blank($magang->email)) {
            return 'Akun sudah dibuat. Lengkapi email pribadi peserta untuk mengirim pemberitahuan.';
        }

        try {
            $login = rtrim(config('magang.frontend_url'), '/').'/login';
            $account = User::find($magang->user_id);
            $password = $magang->password_awal;
            $passwordAwal = $password && $account && Hash::check($password, $account->password)
                ? $password
                : null;
            $this->kirimEmailHasilSeleksi($magang, $login, $passwordAwal);
            return null;
        } catch (\Throwable $e) {
            report($e);
            return 'Akun sudah dibuat, tetapi pengiriman email gagal. Periksa konfigurasi email lalu kirim ulang pemberitahuan.';
        }
    }

    private function kirimEmailHasilSeleksi(Magang $magang, ?string $login = null, ?string $passwordAwal = null): void
    {
        $diterima = $login !== null;
        $subject = $diterima ? 'Penerimaan magang — APTIKA Tools' : 'Hasil seleksi pendaftaran magang — APTIKA Tools';
        \Illuminate\Support\Facades\Mail::send([
            'html' => 'emails.magang.hasil-seleksi',
            'text' => 'emails.magang.hasil-seleksi-text',
        ], [
            'nomor' => $magang->id,
            'nama' => $magang->nama,
            'diterima' => $diterima,
            'emailLogin' => $diterima ? $magang->email_login : null,
            'passwordAwal' => $passwordAwal,
            'login' => $login,
        ], function ($message) use ($magang, $subject) {
            $message->to($magang->email)->subject($subject);
        });
    }

    private function assertMentorCapacity(Magang $participant, int $mentorId): void
    {
        $limit = config('magang.kuota_per_mentor', [])[$mentorId] ?? null;
        // No inferred blanket limit. A confirmed mentor-specific limit is optional.
        if ($limit === null) {
            return;
        }
        $events = [];
        $others = Magang::withoutGlobalScopes()->where('mentor_id', $mentorId)
            ->where('status_seleksi', 'diterima')->where('id', '!=', $participant->id)
            ->whereDate('tgl_mulai_magang', '<=', $participant->tgl_selesai_magang)
            ->whereDate('tgl_selesai_magang', '>=', $participant->tgl_mulai_magang)->get();
        foreach ($others->push($participant) as $other) {
            $start = max($participant->tgl_mulai_magang->toDateString(), $other->tgl_mulai_magang->toDateString());
            $end = min($participant->tgl_selesai_magang->toDateString(), $other->tgl_selesai_magang->toDateString());
            $afterEnd = Carbon::parse($end)->addDay()->toDateString();
            $events[$start] = ($events[$start] ?? 0) + 1;
            $events[$afterEnd] = ($events[$afterEnd] ?? 0) - 1;
        }
        ksort($events);
        $occupancy = 0;
        foreach ($events as $delta) {
            $occupancy += $delta;
            abort_if($occupancy > (int) $limit, 422, 'Kuota mentor pada periode tersebut sudah penuh.');
        }
    }

    public function validasiNda(Request $request, $id)
    {

        return DB::transaction(function () use ($request, $id) {
            $magang = Magang::whereKey($id)->lockForUpdate()->firstOrFail();
            $this->authorizeStaff($request, $magang);
            abort_unless($magang->status_seleksi === 'diterima' && $magang->nda_file, 422,
                'Unggah NDA yang telah ditandatangani sebelum validasi.');
            $magang->update(['nda_validated_at' => now(), 'nda_validated_by' => $request->user()->id]);

            return response()->json(['success' => true, 'data' => $magang]);
        });
    }

    public function penilaian(Request $request, $id)
    {
        $data = $request->validate([
            'versi_rubrik_penilaian' => 'required|string|size:64',
            'nilai' => 'required|array|list|min:1|max:30',
            'nilai.*' => 'required|string|max:255',
            'penilaian' => 'prohibited',
            'aspek_penilaian' => 'prohibited',
            'metode_bobot' => 'prohibited',
            'format_penilaian' => 'prohibited',
            'kampus_perlu_input' => 'prohibited',
            'kampus_sudah_input' => 'prohibited',
            'catatan_penilaian' => 'prohibited',
        ]);
        $gradeChanged = false;
        $magang = DB::transaction(function () use ($request, $id, $data, &$gradeChanged) {
            $magang = Magang::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless(! $request->user()->is_magang && $this->dapatMengaksesPenilaian($request->user(), $magang), 403);
            abort_unless($magang->status_seleksi === 'diterima', 422, 'Peserta belum diterima.');
            if (config('magang.nda_wajib_sebelum_penilaian')) {
                abort_unless($magang->nda_validated_at, 422, 'Konfigurasi saat ini mensyaratkan validasi NDA sebelum penilaian.');
            }
            abort_if(now('Asia/Jakarta')->toDateString() < $magang->tgl_selesai_magang->toDateString(), 422, 'Periode magang belum selesai.');
            abort_if($magang->disahkan_at, 422, 'Nilai dikunci setelah sertifikat disahkan.');

            $rubrik = $this->rubrikPenilaian($magang);
            $metodeBobot = $magang->kebutuhan_kampus['metode_bobot'] ?? null;
            abort_if($rubrik === [], 422, 'Peserta belum menyimpan aspek penilaian kampus.');
            abort_unless($data['versi_rubrik_penilaian'] === $this->versiRubrikPenilaian($rubrik, $metodeBobot), 409,
                'Aspek penilaian telah diperbarui. Buka ulang detail peserta sebelum mengisi nilai.');
            abort_unless(count($data['nilai']) === count($rubrik), 422, 'Isi nilai untuk setiap aspek penilaian.');

            $legacy = ! isset($magang->kebutuhan_kampus['aspek_penilaian']);
            $grades = [];
            foreach ($rubrik as $index => $row) {
                $nilai = $data['nilai'][$index];
                if ($metodeBobot !== null) {
                    $min = $row['nilai_min'];
                    $max = $row['nilai_maks'];
                    abort_unless(is_numeric($nilai) && (float) $nilai >= $min && (float) $nilai <= $max, 422,
                        'Nilai untuk aspek '.$row['aspek'].' harus berupa angka antara '.$min.' dan '.$max.'.');
                } elseif ($this->skalaSeratus($row) || ($legacy && isset($row['bobot']))) {
                    abort_unless(is_numeric($nilai) && (float) $nilai >= 0 && (float) $nilai <= 100, 422,
                        'Nilai untuk aspek '.$row['aspek'].' harus berupa angka 0–100.');
                }
                $grades[] = [...$row, 'nilai' => $nilai];
            }

            if ($magang->dinilai_at && array_column($this->nilaiTersimpan($magang), 'nilai') === $data['nilai']) {
                return $magang;
            }

            $magang->update([
                'penilaian' => $grades,
                'format_penilaian' => $legacy ? $magang->format_penilaian : 'Format kampus peserta',
                'dinilai_at' => now(),
            ]);
            $gradeChanged = true;
            $magang->dokumens()->where('jenis', 'penilaian')->update(['status' => 'perlu_revisi']);
            $magang->dokumens()->firstOrCreate(['jenis' => 'selesai'], ['status' => 'diproses', 'dicatat_oleh' => $request->user()->id]);

            return $magang;
        });

        if ($gradeChanged) {
            $this->notificationService->notifyMagangRatingSubmitted($magang, $request->user());
        }

        return response()->json(['success' => true, 'data' => $this->serializeMagang($magang)]);
    }

    public function pengesahan(Request $request, $id)
    {
        $magang = Magang::findOrFail($id);
        $this->authorizeStaff($request, $magang);
        abort_unless($request->user()->role === 'admin', 403, 'Pencatatan pengesahan hanya dapat dilakukan admin.');
        $data = $request->validate([
            'nomor_sertifikat' => 'required|string|max:255|unique:magangs,nomor_sertifikat,'.$magang->id,
            'sertifikat_file' => 'required|file|mimes:pdf|max:10240',
        ]);
        $magang = DB::transaction(function () use ($request, $id, $data) {
            $magang = Magang::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless($magang->status_seleksi === 'diterima' && $magang->dinilai_at, 422, 'Selesaikan penilaian peserta terlebih dahulu.');
            if (config('magang.nda_wajib_sebelum_penilaian')) {
                abort_unless($magang->nda_validated_at, 422, 'Konfigurasi saat ini mensyaratkan validasi NDA.');
            }
            abort_if($magang->disahkan_at, 422, 'Sertifikat sudah disahkan.');
            $data['sertifikat_file'] = $request->file('sertifikat_file')->store('sertifikat-magang', 'public');
            $magang->update([...$data, 'disahkan_at' => now(), 'disahkan_by' => $request->user()->id, 'sertifikat' => 'Sudah menerima']);

            return $magang;
        });

        $this->notificationService->notifyMagangCertificateFinalized($magang, $request->user());

        return response()->json(['success' => true, 'data' => $magang]);
    }

    private function serializeMagang(Magang $magang): array
    {
        $magang->loadMissing(['bidang', 'dokumens', 'mentor:id,name']);
        $grades = $this->nilaiTersimpan($magang);
        $legacy = ! isset($magang->kebutuhan_kampus['aspek_penilaian']);
        $metodeBobot = $magang->kebutuhan_kampus['metode_bobot'] ?? null;
        $grades = array_map(fn ($row) => [...$row, 'nilai_terbobot' => isset($row['bobot']) && ($metodeBobot === 'berbeda' || $legacy || $this->skalaSeratus($row))
            ? round((float) $row['nilai'] * ((float) $row['bobot'] / 100), 2) : null], $grades);
        $canAccess = $this->dapatMengaksesPenilaian(auth()->user(), $magang);
        $rubrik = $canAccess ? $this->rubrikPenilaian($magang) : [];

        $resource = [
            ...$magang->toArray(),
            'kebutuhan_kampus' => $canAccess ? $magang->kebutuhan_kampus : null,
            'metode_bobot' => $canAccess ? $metodeBobot : null,
            'rubrik_penilaian' => $rubrik,
            'versi_rubrik_penilaian' => $rubrik ? $this->versiRubrikPenilaian($rubrik, $metodeBobot) : null,
            'lampiran_pendaftaran' => collect($magang->lampiran_pendaftaran ?? [])->reject(fn ($file, $key) => ! $canAccess && $key === 'pedoman_kampus')->map(fn ($file, $key) => [
                'jenis' => $key, 'nama' => $file['nama'], 'label' => $file['label'],
                'url' => url('api/magang/'.$magang->id.'/lampiran/'.$key),
            ])->values()->all(),
            'foto_url' => $magang->pas_foto ? url('api/magang/'.$magang->id.'/foto') : null,
            'ringkasan_nilai' => $this->ringkasanNilai($grades, $metodeBobot),
            'penilaian' => $grades,
            'tgl_mulai' => $magang->tgl_mulai_magang?->toDateString(),
            'tgl_selesai' => $magang->tgl_selesai_magang?->toDateString(),
            'cv_magang' => $this->getCvUrl($magang->cv_magang),
            'nda_file' => $this->getCvUrl($magang->nda_file),
            'sertifikat_file' => $this->getCvUrl($magang->sertifikat_file),
            'pengingat' => $this->pengingat($magang),
            'pengingat_hari' => $magang->pengingat_hari ?? config('magang.pengingat_hari', [14, 7]),
            'dokumens' => $magang->dokumens->map(fn ($d) => [...$d->toArray(), 'file' => $this->getCvUrl($d->file)])->all(),
        ];
        unset($resource['pas_foto']);
        if (auth()->user()?->is_magang) {
            unset($resource['riwayat_perubahan'], $resource['catatan_seleksi']);
        }

        return $resource;
    }

    public function options(Request $request)
    {
        $data = $request->validate(['bidang_id' => 'nullable|integer|exists:bidangs,id']);
        $bidangId = $data['bidang_id'] ?? $request->user()->bidang_id;
        abort_unless($request->user()->isAdminAptika() || (int) $bidangId === (int) $request->user()->bidang_id, 403);

        return response()->json(['data' => [
            'mentors' => User::where('bidang_id', $bidangId)->where('is_active', 1)->where('is_magang', false)->orderBy('name')->get(['id', 'name', 'position'])
                ->map(fn ($u) => [...$u->toArray(), 'kuota' => config('magang.kuota_per_mentor', [])[$u->id] ?? null]),
            'rubrik_telkom' => config('magang.rubrik_telkom', []),
            'jurusan_prioritas' => config('magang.jurusan_prioritas', []),
            'nda_wajib_sebelum_penilaian' => (bool) config('magang.nda_wajib_sebelum_penilaian', false),
        ]]);
    }

    public function aturPengingat(Request $request, $id)
    {
        $data = $request->validate([
            'pengingat_hari' => 'present|array|max:10',
            'pengingat_hari.*' => 'required|integer|between:1,60|distinct',
        ]);
        $magang = Magang::findOrFail($id);
        $this->authorizeStaff($request, $magang);
        rsort($data['pengingat_hari']);
        $magang->update($data);

        return response()->json(['success' => true, 'data' => $this->serializeMagang($magang)]);
    }

    public function dokumen(Request $request, $id)
    {
        $data = $request->validate([
            'jenis' => ['required', Rule::in(['penerimaan', 'selesai', 'penilaian'])],
            'status' => ['required', Rule::in(['diproses', 'disahkan'])],
            'nomor' => 'required_if:status,disahkan|nullable|string|max:255',
            'pengesah' => 'required_if:status,disahkan|nullable|string|max:255',
            'file' => 'required_if:status,disahkan|nullable|file|mimes:pdf|max:10240',
            'catatan' => 'nullable|string|max:5000',
        ]);

        return DB::transaction(function () use ($request, $id, $data) {
            $magang = Magang::whereKey($id)->lockForUpdate()->firstOrFail();
            $this->authorizeStaff($request, $magang);
            abort_unless($magang->status_seleksi === 'diterima', 422, 'Surat hanya dapat diproses untuk peserta diterima.');
            if ($data['status'] === 'disahkan') {
                abort_unless($request->user()->role === 'admin', 403, 'Pencatatan dokumen disahkan dilakukan admin berdasarkan dokumen resmi.');
                if ($data['jenis'] !== 'penerimaan') {
                    abort_if(now('Asia/Jakarta')->toDateString() < $magang->tgl_selesai_magang->toDateString(), 422, 'Periode magang belum selesai.');
                }
            }
            if ($data['jenis'] === 'penilaian') {
                abort_unless($magang->dinilai_at, 422, 'Isi penilaian terlebih dahulu.');
            }
            $existing = $magang->dokumens()->where('jenis', $data['jenis'])->first();
            abort_if($existing?->status === 'disahkan', 422, 'Dokumen disahkan tidak dapat ditimpa.');
            if ($request->hasFile('file')) {
                $data['file'] = $request->file('file')->store('surat-magang', 'public');
            } else {
                unset($data['file']);
            }
            $data['dicatat_oleh'] = $request->user()->id;
            $doc = $magang->dokumens()->updateOrCreate(['jenis' => $data['jenis']], $data);

            return response()->json(['success' => true, 'data' => [...$doc->toArray(), 'file' => $this->getCvUrl($doc->file)]]);
        });
    }

    public function kebutuhanKampus(Request $request, $id)
    {
        abort_unless($request->user()->is_magang, 403, 'Kebutuhan penilaian hanya dapat diisi peserta magang.');
        $data = $request->validate([
            'kampus_perlu_input' => 'sometimes|required|boolean',
            'kampus_sudah_input' => 'prohibited',
            'link' => 'sometimes|nullable|url:http,https|max:2000',
            'panduan' => 'sometimes|nullable|string|max:10000',
            'dokumen' => 'nullable|file|mimes:pdf|max:10240',
            'hapus_dokumen' => 'sometimes|boolean',
            'metode_bobot' => 'required_with:aspek_penilaian|in:sama,berbeda',
            'aspek_penilaian' => 'required_with:metode_bobot|array|list|min:1|max:30',
            'aspek_penilaian.*' => 'required|array:aspek,nilai_min,nilai_maks,bobot',
            'aspek_penilaian.*.aspek' => 'required|string|max:255|distinct:ignore_case',
            'aspek_penilaian.*.nilai_min' => 'required|numeric',
            'aspek_penilaian.*.nilai_maks' => 'required|numeric',
            'aspek_penilaian.*.bobot' => 'required_if:metode_bobot,berbeda|prohibited_if:metode_bobot,sama|numeric|decimal:0,2|between:0,100',
        ]);
        $hasGuideInput = array_intersect(array_keys($data), [
            'link', 'panduan', 'dokumen', 'hapus_dokumen',
        ]) !== [];
        if (isset($data['aspek_penilaian'])) {
            $totalWeight = 0;
            foreach ($data['aspek_penilaian'] as $row) {
                $min = (float) $row['nilai_min'];
                $max = (float) $row['nilai_maks'];
                abort_unless(is_finite($min) && is_finite($max) && $min < $max, 422,
                    'Nilai terendah untuk aspek '.$row['aspek'].' harus lebih kecil dari nilai tertinggi.');
                $totalWeight += (int) round(($row['bobot'] ?? 0) * 100);
            }
            abort_if($data['metode_bobot'] === 'berbeda' && $totalWeight !== 10000, 422, 'Total bobot semua aspek harus tepat 100%.');
        }

        $oldPath = $newPath = null;
        $guideChanged = false;
        try {
            $magang = DB::transaction(function () use ($request, $id, $data, $hasGuideInput, &$oldPath, &$newPath, &$guideChanged) {
                $magang = Magang::whereKey($id)->where('user_id', $request->user()->id)->lockForUpdate()->firstOrFail();
                abort_unless($magang->status_seleksi === 'diterima', 422, 'Peserta belum diterima.');
                if (isset($data['kampus_perlu_input'])) {
                    $guideChanged = $guideChanged || (bool) $magang->kampus_perlu_input !== (bool) $data['kampus_perlu_input'];
                    $this->setKewajibanKampus($magang, (bool) $data['kampus_perlu_input']);
                }
                abort_if($hasGuideInput && ! $magang->kampus_perlu_input, 422, 'Aktifkan kewajiban pada sistem kampus untuk menyimpan tautan atau panduan.');
                if (isset($data['aspek_penilaian'])) {
                    abort_if($magang->dinilai_at || $magang->disahkan_at, 422, 'Aspek penilaian dikunci setelah nilai disimpan.');
                }

                $details = collect($data)->only(['link', 'panduan', 'metode_bobot', 'aspek_penilaian'])->all();
                if ($details) {
                    $existingDetails = $magang->kebutuhan_kampus ?? [];
                    foreach ($details as $key => $value) {
                        $guideChanged = $guideChanged || ($existingDetails[$key] ?? null) !== $value;
                    }
                    $magang->kebutuhan_kampus = [...($magang->kebutuhan_kampus ?? []), ...$details];
                }
                if ($file = $request->file('dokumen')) {
                    $attachments = $magang->lampiran_pendaftaran ?? [];
                    $oldPath = $attachments['pedoman_kampus']['path'] ?? null;
                    $newPath = $file->store('magang-pendaftaran', 'local');
                    abort_unless($newPath, 500, 'Panduan penilaian belum dapat disimpan.');
                    $attachments['pedoman_kampus'] = ['path' => $newPath, 'nama' => $file->getClientOriginalName(), 'label' => 'Pedoman penilaian kampus'];
                    $magang->lampiran_pendaftaran = $attachments;
                    $guideChanged = true;
                } elseif ($data['hapus_dokumen'] ?? false) {
                    $attachments = $magang->lampiran_pendaftaran ?? [];
                    $oldPath = $attachments['pedoman_kampus']['path'] ?? null;
                    unset($attachments['pedoman_kampus']);
                    $magang->lampiran_pendaftaran = $attachments;
                    $guideChanged = $guideChanged || (bool) $oldPath;
                }
                $magang->save();

                return $magang;
            });
        } catch (Throwable $exception) {
            if ($newPath) {
                Storage::disk('local')->delete($newPath);
            }
            throw $exception;
        }
        if ($oldPath) {
            Storage::disk('local')->delete($oldPath);
        }
        if ($guideChanged) {
            $this->notificationService->notifyMagangCampusGuidanceChanged($magang, $request->user());
        }

        return response()->json(['success' => true, 'data' => $this->serializeMagang($magang)]);
    }

    public function aturKampus(Request $request, $id)
    {
        $user = $request->user();
        $magang = DB::transaction(function () use ($request, $id, $user) {
            $magang = Magang::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless($this->dapatMengaksesPenilaian($user, $magang) &&
                ($user->is_magang || (int) $magang->mentor_id === (int) $user->id), 403,
                'Status kampus hanya dapat diperbarui oleh peserta atau mentor yang ditugaskan.');
            abort_unless($magang->status_seleksi === 'diterima', 422, 'Peserta belum diterima.');

            if ($user->is_magang) {
                $data = $request->validate([
                    'kampus_perlu_input' => 'required|boolean',
                    'kampus_sudah_input' => 'prohibited',
                ]);
                $this->setKewajibanKampus($magang, (bool) $data['kampus_perlu_input']);
                $magang->save();
            } else {
                $request->validate([
                    'kampus_perlu_input' => 'prohibited',
                    'kampus_sudah_input' => 'required|accepted',
                ]);
                abort_unless($magang->kampus_perlu_input, 422, 'Peserta belum mencatat kewajiban pada sistem kampus.');
                $magang->update(['kampus_sudah_input' => true]);
            }

            return $magang;
        });

        return response()->json(['success' => true, 'data' => $this->serializeMagang($magang)]);
    }

    private function setKewajibanKampus(Magang $magang, bool $required): void
    {
        $magang->kampus_sudah_input = $required && $magang->kampus_perlu_input && $magang->kampus_sudah_input;
        $magang->kampus_perlu_input = $required;
    }

    private function ringkasanNilai(array $grades, ?string $metodeBobot): ?array
    {
        if ($metodeBobot === 'sama' && $grades) {
            $count = count($grades);

            return ['rata_rata' => round(array_sum(array_map(fn ($row) => (float) $row['nilai'] / $count, $grades)), 2)];
        }

        $weighted = array_filter($grades, fn ($r) => isset($r['bobot']));
        if (! $weighted) {
            return null;
        }

        $allWeightedScores = ! in_array(null, array_column($weighted, 'nilai_terbobot'), true);

        return [
            'total_bobot' => round(array_sum(array_column($weighted, 'bobot')), 2),
            'total_terbobot' => $allWeightedScores
                ? round(array_sum(array_map(fn ($r) => (float) $r['nilai'] * ((float) $r['bobot'] / 100), $weighted)), 2) : null,
        ];
    }

    private function nilaiTersimpan(Magang $magang): array
    {
        $grades = $magang->penilaian ?? [];
        // Read old four-aspect records without discarding their values.
        if (! array_is_list($grades)) {
            return collect($grades)->map(fn ($nilai, $aspek) => [
                'aspek' => ucfirst($aspek), 'nilai' => (string) $nilai, 'skala' => '0–100 (format sebelumnya)',
            ])->values()->all();
        }

        return $grades;
    }

    private function rubrikPenilaian(Magang $magang): array
    {
        return $magang->kebutuhan_kampus['aspek_penilaian'] ?? array_map(
            fn ($row) => collect($row)->only(['aspek', 'skala', 'bobot'])->all(), $this->nilaiTersimpan($magang)
        );
    }

    private function versiRubrikPenilaian(array $rubrik, ?string $metodeBobot): string
    {
        if ($metodeBobot !== null) {
            $rubrik = array_map(fn ($row) => [
                'aspek' => $row['aspek'],
                'nilai_min' => (float) $row['nilai_min'],
                'nilai_maks' => (float) $row['nilai_maks'],
                'bobot' => isset($row['bobot']) ? (float) $row['bobot'] : null,
            ], $rubrik);

            return hash('sha256', json_encode([$metodeBobot, $rubrik], JSON_THROW_ON_ERROR));
        }

        $rubrik = array_map(fn ($row) => [
            'aspek' => $row['aspek'],
            'skala' => $row['skala'] ?? null,
            'bobot' => isset($row['bobot']) ? (float) $row['bobot'] : null,
        ], $rubrik);

        return hash('sha256', json_encode($rubrik, JSON_THROW_ON_ERROR));
    }

    private function skalaSeratus(array $row): bool
    {
        return preg_match('/^0\s*[-–—]\s*100$/u', trim($row['skala'] ?? '')) === 1;
    }

    public function pendaftaranInfo()
    {
        return response()->json(['data' => ['dokumen' => config('magang.dokumen_pendaftaran'), 'domain_akun' => 'magang.com']]);
    }

    public function lampiran(Request $request, $id, $jenis)
    {
        $magang = Magang::findOrFail($id);
        abort_if($jenis === 'pedoman_kampus' && ! $this->dapatMengaksesPenilaian($request->user(), $magang), 403);
        $file = ($magang->lampiran_pendaftaran ?? [])[$jenis] ?? null;
        abort_unless($file && Storage::disk('local')->exists($file['path']), 404);

        return Storage::disk('local')->download($file['path'], $file['nama']);
    }

    private function buatAkunPeserta(Magang $participant): array
    {
        abort_if($participant->user_id, 422, 'Peserta sudah memiliki akun.');
        $loginEmail = $participant->email_login ?: 'magang.'.$participant->id.'@magang.com';
        abort_if(User::where('email', $loginEmail)->exists(), 422, 'Email akun sudah digunakan. Hubungi admin sebelum melanjutkan approval.');
        $password = Str::random(24);
        $account = new User([
            'name' => $participant->nama, 'email' => $loginEmail, 'password' => $password,
            'role' => 'user', 'is_active' => 1, 'bidang_id' => $participant->bidang_id,
            'phone' => $participant->telepon, 'position' => 'Peserta Magang',
        ]);
        if ($participant->pas_foto && Storage::disk('local')->exists($participant->pas_foto)) {
            $avatar = 'avatars/magang/'.basename($participant->pas_foto);
            abort_unless(Storage::disk('public')->put($avatar, Storage::disk('local')->get($participant->pas_foto)), 500, 'Foto profil belum dapat disimpan.');
            $account->avatar = $avatar;
        }
        $account->forceFill(['is_magang' => true])->save();

        return ['user_id' => $account->id, 'email_login' => $loginEmail, 'password_awal' => $password];
    }

    public function aktivasiAkun(Request $request, $id)
    {
        $participant = DB::transaction(function () use ($request, $id) {
            $participant = Magang::whereKey($id)->lockForUpdate()->firstOrFail();
            $this->authorizeStaff($request, $participant);
            abort_unless($participant->status_seleksi === 'diterima', 422, 'Akun hanya dibuat untuk peserta yang telah diterima mentor.');
            $participant->update($this->buatAkunPeserta($participant));

            return $participant;
        });

        $warning = $this->kirimPenerimaan($participant);
        return response()->json(['success' => true, 'data' => $this->serializeMagang($participant), 'warning' => $warning]);
    }

    public function foto(Request $request, $id)
    {
        $magang = Magang::findOrFail($id);
        abort_unless($magang->pas_foto && Storage::disk('local')->exists($magang->pas_foto), 404);

        return response()->file(Storage::disk('local')->path($magang->pas_foto), ['Cache-Control' => 'private, no-store']);
    }
}
