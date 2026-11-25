<?php

namespace Tests\Feature\Magang;

use App\Models\Bidang;
use App\Models\Magang;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MagangWorkflowTest extends TestCase
{
    use RefreshDatabase;

    // Run only the real migrations used by this module. Unrelated legacy migrations
    // contain MySQL-specific SQL and cannot run against the isolated SQLite database.
    protected function migrateFreshUsing(): array
    {
        return ['--path' => array_map(fn ($file) => 'database/migrations/'.$file, [
            '0001_01_01_000000_create_users_table.php',
            '2026_07_20_062319_create_magangs_table.php',
            '2026_07_20_073100_drop_status_magang_from_magangs_table.php',
            '2026_08_04_000000_add_nda_file_to_magangs_table.php',
            '2026_08_20_000001_create_bidangs_table.php',
            '2026_08_20_000002_create_services_table.php',
            '2026_08_20_000003_create_bidang_services_table.php',
            '2026_08_20_000004_add_bidang_id_to_users_table.php',
            '2026_08_20_000005_add_bidang_id_to_transaksi_tables.php',
            '2026_08_20_000006_add_parent_id_to_services_table.php',
            '2026_11_10_000001_add_workflow_to_magangs_table.php',
            '2026_11_11_000001_refine_magang_interview_workflow.php',
            '2026_11_11_000002_add_magang_accounts_and_registration_files.php',
            '2026_11_11_000003_add_pas_foto_to_magangs_table.php',
            '2026_11_11_000004_add_campus_requirements_to_magangs.php',
            '2026_11_11_000005_add_password_awal_to_magangs_table.php',
            '2026_05_10_165339_create_personal_access_tokens_table.php',
        ])];
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        $this->travelTo(now('Asia/Jakarta')->setDate(2026, 11, 10)->startOfDay());
    }

    private function staff(string $code = 'TEST', string $role = 'staff'): User
    {
        $bidang = Bidang::firstOrCreate(['code' => $code], ['name' => $code]);
        $user = User::factory()->create(['role' => $role, 'bidang_id' => $bidang->id, 'is_active' => 1]);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    private function applicant(array $overrides = []): Magang
    {
        $bidang = Bidang::firstOrCreate(['code' => 'TEST'], ['name' => 'TEST']);

        return Magang::create(array_merge([
            'nama' => 'Peserta', 'nama_kampus' => 'Universitas', 'nim' => '123',
            'jurusan' => 'SI', 'email' => 'peserta@example.com', 'telepon' => '08123456789',
            'alamat' => 'Bandung', 'memiliki_laptop' => true, 'bidang_id' => $bidang->id,
            'tgl_mulai_magang' => '2026-09-01', 'tgl_selesai_magang' => '2026-11-10',
            'cv_magang' => 'cv-magang/example.pdf', 'sertifikat' => 'Belum menerima',
        ], $overrides));
    }

    private function rubric(): array
    {
        return [
            ['aspek' => 'Analisis proses bisnis', 'skala' => 'A–E'],
            ['aspek' => 'Komunikasi', 'skala' => 'Predikat'],
        ];
    }

    private function grades(Magang $participant, array $scores = ['A', 'Baik']): array
    {
        $version = $this->getJson('/api/magang/'.$participant->id)->assertOk()->json('data.versi_rubrik_penilaian');

        return ['versi_rubrik_penilaian' => $version ?? str_repeat('0', 64), 'nilai' => $scores];
    }

    public function test_admin_user_list_exposes_intern_category_including_inactive_accounts_with_bidang_scope(): void
    {
        $admin = $this->staff('TEST', 'admin');
        // Email alone must not identify an intern account.
        $staff = User::factory()->create(['bidang_id' => $admin->bidang_id, 'email' => 'staff@magang.com']);
        $intern = User::factory()->create(['bidang_id' => $admin->bidang_id, 'is_active' => 1]);
        $intern->forceFill(['is_magang' => true])->save();
        $inactive = User::factory()->create(['bidang_id' => $admin->bidang_id, 'is_active' => 0]);
        $inactive->forceFill(['is_magang' => true])->save();
        $otherBidang = Bidang::create(['code' => 'OTHER', 'name' => 'Other']);
        $otherIntern = User::factory()->create(['bidang_id' => $otherBidang->id]);
        $otherIntern->forceFill(['is_magang' => true])->save();

        $rows = $this->getJson('/api/admin/users')->assertOk()->json();
        $byId = collect($rows)->keyBy('id');
        $this->assertCount(4, $rows);
        $this->assertFalse($byId[$staff->id]['is_magang']);
        $this->assertTrue($byId[$intern->id]['is_magang']);
        $this->assertTrue($byId[$inactive->id]['is_magang']);
        $this->assertSame(0, $byId[$inactive->id]['is_active']);
        $this->assertArrayNotHasKey($otherIntern->id, $byId->all());

        $this->staff('APTIKA', 'admin');
        $this->getJson('/api/admin/users')->assertOk()->assertJsonFragment([
            'id' => $otherIntern->id, 'is_magang' => true,
        ]);
    }

    public function test_rejection_email_targets_applicant_without_exposing_internal_notes_or_creating_account(): void
    {
        config(['mail.default' => 'smtp']);
        \Illuminate\Support\Facades\Mail::shouldReceive('send')->twice()->withArgs(function ($views, $data, $callback) {
            $message = new \Illuminate\Mail\Message(new \Symfony\Component\Mime\Email());
            $callback($message);
            $html = view($views['html'], $data)->render();
            $text = view($views['text'], $data)->render();
            foreach ([$html, $text] as $body) {
                $this->assertStringContainsString('belum dapat kami terima', $body);
                $this->assertStringNotContainsString('Internal saja', $body);
                $this->assertStringNotContainsString('Password', $body);
            }
            $this->assertStringNotContainsString('Masuk ke APTIKA Tools', $html);
            return $message->getSymfonyMessage()->getTo()[0]->getAddress() === 'peserta@example.com';
        });
        $this->staff();
        $p = $this->applicant();
        $this->postJson('/api/magang/'.$p->id.'/pemberitahuan-penolakan')->assertUnprocessable();
        $this->patchJson('/api/magang/'.$p->id.'/seleksi', ['status_seleksi' => 'ditolak', 'catatan_seleksi' => 'Internal saja'])->assertOk()->assertJsonPath('warning', null);
        $this->assertNull($p->fresh()->user_id);
        $this->postJson('/api/magang/'.$p->id.'/pemberitahuan-penolakan')->assertOk()->assertJsonPath('success', true);
        $this->staff('OTHER');
        $this->postJson('/api/magang/'.$p->id.'/pemberitahuan-penolakan')->assertNotFound();
    }

    public function test_acceptance_sends_personal_email_with_login_and_nda_instructions(): void
    {
        config(['mail.default' => 'smtp', 'magang.frontend_url' => 'https://aptika.example']);
        $this->staff();
        $p = $this->applicant();
        $messages = [];
        \Illuminate\Support\Facades\Mail::shouldReceive('send')->times(3)->andReturnUsing(function ($views, $data, $callback) use (&$messages) {
            $message = new \Illuminate\Mail\Message(new \Symfony\Component\Mime\Email());
            $callback($message);
            $this->assertSame('peserta@example.com', $message->getSymfonyMessage()->getTo()[0]->getAddress());
            $html = view($views['html'], $data)->render();
            $text = view($views['text'], $data)->render();
            $this->assertStringContainsString('NDA', $text);
            $this->assertStringContainsString('NDA', $html);
            $this->assertStringContainsString('Masuk ke APTIKA Tools</a>', $html);
            $this->assertStringContainsString('href="https://aptika.example/login"', $html);
            $messages[] = ['html' => $html, 'text' => $text];
        });

        $base = '/api/magang/'.$p->id;
        $this->patchJson($base.'/seleksi', ['status_seleksi' => 'diterima', 'jurusan_sesuai' => true])
            ->assertOk()->assertJsonPath('warning', null)->assertJsonMissingPath('data.password_awal');
        $p->refresh();
        $password = $p->password_awal;
        $this->assertStringContainsString("Password awal: {$password}", $messages[0]['text']);
        $this->assertStringContainsString($password, $messages[0]['html']);
        $this->assertNotSame($password, $p->getRawOriginal('password_awal'));
        $this->postJson($base.'/pemberitahuan-penerimaan')->assertOk()->assertJsonPath('success', true);
        $this->assertSame($messages[0], $messages[1]);
        User::findOrFail($p->user_id)->update(['password' => 'password-yang-sudah-diganti']);
        $this->postJson($base.'/pemberitahuan-penerimaan')->assertOk()->assertJsonPath('success', true);
        $this->assertStringNotContainsString($password, $messages[2]['html']);
        $this->assertStringNotContainsString($password, $messages[2]['text']);
        $this->assertStringContainsString('Gunakan password akun Anda saat ini', $messages[2]['text']);
    }

    public function test_participant_submits_campus_guidance_and_rubric_then_only_assigned_mentor_can_grade(): void
    {
        $mentor = $this->staff();
        $intern = User::factory()->create(['bidang_id' => $mentor->bidang_id, 'is_magang' => true, 'is_active' => 1]);
        $p = $this->applicant(['status_seleksi' => 'diterima', 'mentor_id' => $mentor->id, 'user_id' => $intern->id]);
        $other = $this->applicant(['status_seleksi' => 'diterima']);
        $base = '/api/magang/'.$p->id;
        $guide = ['link' => 'https://kampus.example/penilaian', 'panduan' => 'Gunakan skala A–E.'];
        $rubric = [
            ['aspek' => 'Komunikasi', 'nilai_min' => 1, 'nilai_maks' => 5, 'bobot' => 50],
            ['aspek' => 'Ketepatan waktu', 'nilai_min' => 1, 'nilai_maks' => 5, 'bobot' => 50],
        ];

        $this->postJson($base.'/kebutuhan-kampus', [...$guide, 'kampus_perlu_input' => true])->assertForbidden();
        $this->postJson($base.'/penilaian', $this->grades($p))->assertUnprocessable();
        $this->actingAs($intern, 'sanctum');
        $this->postJson('/api/magang/'.$other->id.'/kebutuhan-kampus', $guide)->assertNotFound();
        $this->postJson($base.'/kebutuhan-kampus', $guide)->assertUnprocessable();
        $this->postJson($base.'/kebutuhan-kampus', ['dokumen' => UploadedFile::fake()->create('panduan.pdf', 20, 'application/pdf')])->assertUnprocessable();
        $this->postJson($base.'/kebutuhan-kampus', ['link' => 'javascript:alert(1)'])->assertUnprocessable();
        $this->postJson($base.'/kebutuhan-kampus', ['kampus_perlu_input' => true, 'dokumen' => UploadedFile::fake()->create('panduan.docx', 20)])
            ->assertUnprocessable()->assertJsonValidationErrors('dokumen');
        $this->assertFalse($p->fresh()->kampus_perlu_input);
        $this->post($base.'/kebutuhan-kampus', [
            ...$guide, 'kampus_perlu_input' => '1', 'dokumen' => UploadedFile::fake()->create('panduan.pdf', 20, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.kampus_perlu_input', true)
            ->assertJsonPath('data.kebutuhan_kampus.panduan', $guide['panduan']);
        $previousGuide = $p->fresh()->lampiran_pendaftaran['pedoman_kampus']['path'];
        Storage::disk('local')->assertExists($previousGuide);

        $this->postJson($base.'/kebutuhan-kampus', ['metode_bobot' => 'berbeda', 'aspek_penilaian' => [$rubric[0], [...$rubric[1], 'bobot' => 51]]])->assertUnprocessable();
        $this->postJson($base.'/kebutuhan-kampus', ['metode_bobot' => 'berbeda', 'aspek_penilaian' => [$rubric[0], [...$rubric[1], 'bobot' => 49.99]]])->assertUnprocessable();
        $this->postJson($base.'/kebutuhan-kampus', ['metode_bobot' => 'berbeda', 'aspek_penilaian' => [
            [...$rubric[0], 'bobot' => 100],
            ['aspek' => 'Ketepatan waktu', 'nilai_min' => 1, 'nilai_maks' => 5],
        ]])->assertUnprocessable()->assertJsonValidationErrors('aspek_penilaian.1.bobot');
        $this->postJson($base.'/kebutuhan-kampus', ['metode_bobot' => 'berbeda', 'aspek_penilaian' => [$rubric[0], [...$rubric[1], 'aspek' => 'KOMUNIKASI']]])
            ->assertUnprocessable()->assertJsonValidationErrors('aspek_penilaian.1.aspek');
        $this->postJson($base.'/kebutuhan-kampus', ['metode_bobot' => 'berbeda', 'aspek_penilaian' => $rubric])
            ->assertOk()->assertJsonPath('data.rubrik_penilaian', $rubric);
        $stale = $this->grades($p, ['4', '5']);
        $rubric[0]['bobot'] = 55;
        $rubric[1]['bobot'] = 45;
        $this->postJson($base.'/kebutuhan-kampus', ['metode_bobot' => 'berbeda', 'aspek_penilaian' => $rubric])->assertOk();
        $scores = $this->grades($p, ['4', '5']);
        $this->postJson($base.'/penilaian', $scores)->assertForbidden();

        $this->staff();
        $this->getJson($base)->assertOk()->assertJsonPath('data.kebutuhan_kampus', null)->assertJsonCount(0, 'data.lampiran_pendaftaran');
        $this->get($base.'/lampiran/pedoman_kampus')->assertForbidden();
        $this->postJson($base.'/penilaian', $scores)->assertForbidden();

        $this->actingAs($mentor, 'sanctum');
        $this->getJson($base)->assertOk()->assertJsonPath('data.kebutuhan_kampus.link', $guide['link']);
        $this->get($base.'/lampiran/pedoman_kampus')->assertDownload('panduan.pdf');
        $this->postJson($base.'/penilaian', $stale)->assertConflict();
        $this->postJson($base.'/penilaian', [...$scores, 'nilai' => ['A']])->assertUnprocessable();
        $this->postJson($base.'/penilaian', [...$scores, 'metode_bobot' => 'berbeda', 'aspek_penilaian' => $rubric])->assertUnprocessable();
        $this->patchJson($base.'/kampus', ['kampus_perlu_input' => true, 'kampus_sudah_input' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('kampus_perlu_input');
        $this->postJson($base.'/penilaian', $scores)->assertOk()
            ->assertJsonPath('data.penilaian.0.nilai', '4')
            ->assertJsonPath('data.penilaian.1.bobot', 45)
            ->assertJsonPath('data.penilaian.1.nilai_terbobot', 2.25)
            ->assertJsonPath('data.ringkasan_nilai.total_bobot', 100)
            ->assertJsonPath('data.ringkasan_nilai.total_terbobot', 4.45);

        $this->actingAs($intern, 'sanctum');
        $this->postJson($base.'/kebutuhan-kampus', ['metode_bobot' => 'berbeda', 'aspek_penilaian' => $rubric])->assertUnprocessable();
        $this->postJson($base.'/kebutuhan-kampus', ['dokumen' => UploadedFile::fake()->create('revisi.pdf', 20, 'application/pdf')])->assertOk();
        Storage::disk('local')->assertMissing($previousGuide);
        $this->getJson($base)->assertOk()->assertJsonPath('data.rubrik_penilaian', $rubric)->assertJsonPath('data.penilaian.0.nilai', '4');
        $this->postJson($base.'/kebutuhan-kampus', ['kampus_perlu_input' => '0'])->assertOk()
            ->assertJsonPath('data.kampus_perlu_input', false);
        $this->postJson($base.'/kebutuhan-kampus', ['link' => null])->assertUnprocessable();
        $this->postJson($base.'/kebutuhan-kampus', ['kampus_perlu_input' => true])->assertOk()
            ->assertJsonPath('data.kebutuhan_kampus.link', $guide['link']);
    }

    public function test_equal_weights_average_numeric_scores_and_enforce_each_aspect_range(): void
    {
        $mentor = $this->staff();
        $intern = User::factory()->create(['bidang_id' => $mentor->bidang_id, 'is_magang' => true, 'is_active' => 1]);
        $p = $this->applicant(['status_seleksi' => 'diterima', 'mentor_id' => $mentor->id, 'user_id' => $intern->id]);
        $base = '/api/magang/'.$p->id;
        $rubric = [
            ['aspek' => 'Komunikasi', 'nilai_min' => 1, 'nilai_maks' => 5],
            ['aspek' => 'Ketepatan waktu', 'nilai_min' => 0, 'nilai_maks' => 5],
        ];

        $this->actingAs($intern, 'sanctum');
        $this->postJson($base.'/kebutuhan-kampus', ['aspek_penilaian' => $rubric])
            ->assertUnprocessable()->assertJsonValidationErrors('metode_bobot');
        $this->postJson($base.'/kebutuhan-kampus', ['metode_bobot' => 'sama', 'aspek_penilaian' => [
            [...$rubric[0], 'nilai_min' => 5], $rubric[1],
        ]])->assertUnprocessable();
        $this->postJson($base.'/kebutuhan-kampus', ['metode_bobot' => 'sama', 'aspek_penilaian' => $rubric])
            ->assertOk()->assertJsonPath('data.metode_bobot', 'sama');

        $this->actingAs($mentor, 'sanctum');
        foreach ([['0', '5'], ['1', '5.01'], ['A', '5']] as $scores) {
            $this->postJson($base.'/penilaian', $this->grades($p, $scores))->assertUnprocessable();
        }
        $this->postJson($base.'/penilaian', $this->grades($p, ['5', '0']))
            ->assertOk()->assertJsonPath('data.ringkasan_nilai.rata_rata', 2.5)
            ->assertJsonPath('data.penilaian.1.nilai_min', 0);
    }

    public function test_public_registration_requires_identity_and_cannot_self_approve(): void
    {
        $bidang = Bidang::create(['code' => 'TEST', 'name' => 'TEST']);
        $data = ['nama' => 'Peserta', 'nama_kampus' => 'Kampus', 'bidang_id' => $bidang->id,
            'tgl_mulai_magang' => '2026-11-01', 'tgl_selesai_magang' => '2026-12-01',
            'cv_magang' => UploadedFile::fake()->create('cv.pdf', 50, 'application/pdf')];
        $this->postJson('/api/magang', $data)->assertUnprocessable()->assertJsonValidationErrors(['nim', 'jurusan', 'memiliki_laptop']);
        $data += ['username' => 'peserta.public', 'surat_pengantar' => UploadedFile::fake()->create('pengantar.pdf', 50, 'application/pdf'), 'nim' => '123', 'jurusan' => 'SI', 'email' => 'p@example.com', 'telepon' => '08123',
            'alamat' => 'Bandung', 'memiliki_laptop' => '1', 'status_seleksi' => 'diterima', 'sertifikat' => 'Sudah menerima'];
        $this->postJson('/api/magang', [...$data,
            'cv_magang' => UploadedFile::fake()->create('cv.docx', 50, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
            'portofolio' => UploadedFile::fake()->create('portofolio.png', 50, 'image/png'),
        ])->assertUnprocessable()->assertJsonValidationErrors(['cv_magang', 'portofolio']);
        $this->postJson('/api/magang', [...$data,
            'cv_magang' => UploadedFile::fake()->create('cv.pdf', 2049, 'application/pdf'),
            'portofolio' => UploadedFile::fake()->create('portofolio.pdf', 5121, 'application/pdf'),
        ])->assertUnprocessable()->assertJsonValidationErrors(['cv_magang', 'portofolio']);
        $this->postJson('/api/magang', $data)->assertCreated()->assertJsonPath('data.sertifikat', 'Belum menerima')->assertJsonPath('data.foto_url', null);
        $this->assertDatabaseHas('magangs', ['nim' => '123', 'status_seleksi' => 'menunggu']);
        $this->postJson('/api/magang', [...$data, 'email' => 'bukan-email'])
            ->assertUnprocessable()->assertJsonValidationErrors(['username', 'email']);
        $data['tgl_selesai_magang'] = '2026-10-01';
        $this->postJson('/api/magang', $data)->assertUnprocessable()->assertJsonValidationErrors('tgl_selesai_magang');
    }

    public function test_full_workflow_enforces_nda_evaluation_and_official_approval(): void
    {
        config(['magang.nda_wajib_sebelum_penilaian' => true]);
        $mentor = $this->staff();
        $p = $this->applicant(['kebutuhan_kampus' => ['aspek_penilaian' => $this->rubric()]]);
        $base = '/api/magang/'.$p->id;
        $this->postJson($base.'/penilaian', $this->grades($p))->assertForbidden();
        $this->patchJson($base.'/seleksi', ['status_seleksi' => 'diterima', 'jurusan_sesuai' => true])->assertOk()->assertJsonPath('data.mentor_id', $mentor->id);
        $this->assertNotNull($p->fresh()->nomor_nda);
        $this->patchJson($base.'/seleksi', ['status_seleksi' => 'ditolak'])->assertUnprocessable();
        $this->putJson($base, ['nama' => 'Changed'])->assertUnprocessable();
        $this->postJson($base.'/validasi-nda')->assertUnprocessable();
        $this->postJson($base.'/upload-nda', ['nda_file' => UploadedFile::fake()->create('nda.pdf', 50, 'application/pdf')])->assertOk();
        $this->postJson($base.'/penilaian', $this->grades($p))->assertUnprocessable();
        $this->postJson($base.'/validasi-nda')->assertOk();
        $this->postJson($base.'/penilaian', ['format_penilaian' => 'Contoh', 'penilaian' => [['nilai' => 'A']], 'kampus_perlu_input' => false, 'kampus_sudah_input' => false])->assertUnprocessable();
        $this->postJson($base.'/penilaian', $this->grades($p))->assertOk();
        $this->postJson($base.'/pengesahan')->assertForbidden();
        $this->staff('TEST', 'admin');
        $this->postJson($base.'/pengesahan', ['nomor_sertifikat' => 'RESMI/001/2026',
            'sertifikat_file' => UploadedFile::fake()->create('sertifikat.pdf', 50, 'application/pdf')])
            ->assertOk()->assertJsonPath('data.sertifikat', 'Sudah menerima');
        $this->postJson($base.'/penilaian', $this->grades($p))->assertUnprocessable();
        $this->postJson($base.'/upload-nda', ['nda_file' => UploadedFile::fake()->create('nda.pdf', 50, 'application/pdf')])->assertUnprocessable();
        $this->getJson('/api/magang')->assertOk()->assertJsonPath('data.0.nomor_sertifikat', 'RESMI/001/2026');
    }

    public function test_approval_checks_peak_capacity_and_isolates_bidang_and_mentor(): void
    {
        $mentor = $this->staff();
        config(['magang.kuota_per_mentor' => [$mentor->id => 1]]);
        $a = $this->applicant();
        $this->patchJson('/api/magang/'.$a->id.'/seleksi', ['status_seleksi' => 'diterima', 'jurusan_sesuai' => true])->assertOk();
        $b = $this->applicant();
        $this->patchJson('/api/magang/'.$b->id.'/seleksi', ['status_seleksi' => 'diterima', 'jurusan_sesuai' => true])->assertUnprocessable();
        $c = $this->applicant(['tgl_mulai_magang' => '2026-11-11', 'tgl_selesai_magang' => '2026-12-01']);
        $this->patchJson('/api/magang/'.$c->id.'/seleksi', ['status_seleksi' => 'diterima', 'jurusan_sesuai' => true])->assertOk();
        $this->staff();
        $this->postJson('/api/magang/'.$a->id.'/validasi-nda')->assertForbidden();
        $this->staff('OTHER');
        $this->getJson('/api/magang/'.$a->id)->assertNotFound();
        $this->patchJson('/api/magang/'.$a->id.'/seleksi', ['status_seleksi' => 'ditolak'])->assertNotFound();
    }

    public function test_future_period_and_public_nda_upload_are_blocked(): void
    {
        $mentor = $this->staff();
        $p = $this->applicant(['tgl_selesai_magang' => '2026-12-01', 'status_seleksi' => 'diterima',
            'mentor_id' => $mentor->id, 'nda_file' => 'nda-file/nda.pdf', 'nda_validated_at' => now()]);
        $this->postJson('/api/magang/'.$p->id.'/penilaian', $this->grades($p))->assertUnprocessable();
        auth('sanctum')->forgetUser();
        auth()->forgetGuards();
        $this->postJson('/api/magang/'.$p->id.'/upload-nda')->assertUnauthorized();
    }

    public function test_replacing_nda_revokes_validation_and_incomplete_legacy_data_cannot_be_accepted(): void
    {
        config(['magang.nda_wajib_sebelum_penilaian' => true]);
        $mentor = $this->staff();
        $legacy = $this->applicant(['nim' => null]);
        $this->patchJson('/api/magang/'.$legacy->id.'/seleksi', ['status_seleksi' => 'diterima', 'jurusan_sesuai' => true])->assertUnprocessable();
        $p = $this->applicant(['status_seleksi' => 'diterima', 'mentor_id' => $mentor->id,
            'nda_file' => 'nda-file/old.pdf', 'nda_validated_at' => now()]);
        $this->postJson('/api/magang/'.$p->id.'/upload-nda', [
            'nda_file' => UploadedFile::fake()->create('new.pdf', 50, 'application/pdf'),
        ])->assertOk();
        $this->assertNull($p->fresh()->nda_validated_at);
        $this->postJson('/api/magang/'.$p->id.'/penilaian', $this->grades($p))->assertUnprocessable();
    }

    public function test_quota_uses_concurrent_peak_and_not_sum_of_all_overlapping_applicants(): void
    {
        $mentor = $this->staff();
        config(['magang.kuota_per_mentor' => [$mentor->id => 2]]);
        $this->applicant(['status_seleksi' => 'diterima', 'mentor_id' => $mentor->id, 'tgl_mulai_magang' => '2026-09-01', 'tgl_selesai_magang' => '2026-09-15']);
        $this->applicant(['status_seleksi' => 'diterima', 'mentor_id' => $mentor->id, 'tgl_mulai_magang' => '2026-09-16', 'tgl_selesai_magang' => '2026-09-30']);
        $p = $this->applicant(['tgl_mulai_magang' => '2026-09-01', 'tgl_selesai_magang' => '2026-09-30']);
        $this->patchJson('/api/magang/'.$p->id.'/seleksi', ['status_seleksi' => 'diterima', 'jurusan_sesuai' => true])->assertOk();
    }

    public function test_flexible_assessment_does_not_impose_four_aspects_or_mandatory_nda_by_default(): void
    {
        $mentor = $this->staff();
        $p = $this->applicant(['status_seleksi' => 'diterima', 'mentor_id' => $mentor->id,
            'kebutuhan_kampus' => ['aspek_penilaian' => $this->rubric()]]);
        $this->postJson('/api/magang/'.$p->id.'/penilaian', $this->grades($p))->assertOk();
        $this->getJson('/api/magang/'.$p->id)->assertOk()
            ->assertJsonPath('data.penilaian.0.aspek', 'Analisis proses bisnis')
            ->assertJsonPath('data.penilaian.0.nilai', 'A')
            ->assertJsonPath('data.format_penilaian', 'Format kampus peserta');
        $data = $this->grades($p);
        $data['penilaian'] = [['aspek' => 'Aspek di luar rubrik', 'nilai' => 'A']];
        $this->postJson('/api/magang/'.$p->id.'/penilaian', $data)->assertUnprocessable();
        $legacy = $this->applicant(['penilaian' => ['teknis' => 87]]);
        $this->getJson('/api/magang/'.$legacy->id)->assertOk()->assertJsonPath('data.penilaian.0.nilai', '87');
    }

    public function test_capacity_is_specific_to_a_configured_mentor_not_an_automatic_bidang_limit(): void
    {
        $first = $this->staff();
        config(['magang.kuota_per_mentor' => [$first->id => 1]]);
        $other = User::factory()->create(['is_active' => 1, 'bidang_id' => $first->bidang_id]);
        $this->applicant(['status_seleksi' => 'diterima', 'mentor_id' => $first->id]);
        $p = $this->applicant(['jurusan' => 'Rekayasa Perangkat Lunak']);
        $this->patchJson('/api/magang/'.$p->id.'/seleksi', ['status_seleksi' => 'diterima', 'mentor_id' => $other->id, 'jurusan_sesuai' => true])
            ->assertOk()->assertJsonPath('data.mentor_id', $other->id);
        $p = $this->applicant();
        $this->patchJson('/api/magang/'.$p->id.'/seleksi', ['status_seleksi' => 'diterima'])->assertUnprocessable()->assertJsonValidationErrors('jurusan_sesuai');
        $outsider = $this->staff('OTHER');
        $this->actingAs($first, 'sanctum');
        $this->patchJson('/api/magang/'.$p->id.'/seleksi', ['status_seleksi' => 'diterima', 'mentor_id' => $outsider->id, 'jurusan_sesuai' => true])->assertUnprocessable();
        $this->getJson('/api/magang/options')->assertOk()->assertJsonCount(2, 'data.mentors');
        $this->getJson('/api/magang/options?bidang_id='.$outsider->bidang_id)->assertForbidden();
        config(['magang.kuota_per_mentor' => []]);
        $this->patchJson('/api/magang/'.$p->id.'/seleksi', ['status_seleksi' => 'diterima', 'jurusan_sesuai' => true])->assertOk();
    }

    public function test_authorized_identity_correction_requires_reason_and_marks_documents_for_review(): void
    {
        $mentor = $this->staff();
        $p = $this->applicant(['status_seleksi' => 'diterima', 'mentor_id' => $mentor->id, 'nda_validated_at' => now()]);
        $p->dokumens()->create(['jenis' => 'penerimaan', 'status' => 'disahkan', 'nomor' => '001']);
        $payload = ['nama' => 'Peserta Dikoreksi', 'nama_kampus' => $p->nama_kampus,
            'tgl_mulai_magang' => '2026-09-01', 'tgl_selesai_magang' => '2026-11-10'];
        $this->putJson('/api/magang/'.$p->id, $payload)->assertUnprocessable();
        $payload['alasan_perubahan'] = 'Nama disesuaikan dengan surat kampus.';
        $this->putJson('/api/magang/'.$p->id, $payload)->assertOk();
        $this->assertNull($p->fresh()->nda_validated_at);
        $this->assertSame('perlu_revisi', $p->dokumens()->first()->status);
        $this->assertSame($payload['alasan_perubahan'], $p->fresh()->riwayat_perubahan[0]['alasan']);
    }

    public function test_surat_tracking_requires_real_document_for_approval_and_is_scoped(): void
    {
        $mentor = $this->staff();
        $p = $this->applicant(['status_seleksi' => 'diterima', 'mentor_id' => $mentor->id]);
        $base = '/api/magang/'.$p->id.'/dokumen';
        $this->postJson($base, ['jenis' => 'penerimaan', 'status' => 'diproses'])->assertOk();
        $this->postJson($base, ['jenis' => 'penerimaan', 'status' => 'disahkan'])->assertUnprocessable()->assertJsonValidationErrors(['nomor', 'file', 'pengesah']);
        $data = ['jenis' => 'penerimaan', 'status' => 'disahkan', 'nomor' => '001/2026', 'pengesah' => 'Pejabat sesuai surat',
            'file' => UploadedFile::fake()->create('surat.pdf', 50, 'application/pdf')];
        $this->postJson($base, $data)->assertForbidden();
        $this->staff('TEST', 'admin');
        $this->postJson($base, $data)->assertOk()->assertJsonPath('data.status', 'disahkan');
        $this->postJson($base, ['jenis' => 'penerimaan', 'status' => 'diproses'])->assertUnprocessable();
        $this->postJson($base, ['jenis' => 'penilaian', 'status' => 'diproses'])->assertUnprocessable();
        $this->staff('OTHER');
        $this->postJson($base, ['jenis' => 'selesai', 'status' => 'diproses'])->assertNotFound();
    }

    public function test_reminders_are_editable_and_continue_for_pending_documents_after_grading(): void
    {
        $mentor = $this->staff();
        $p = $this->applicant(['status_seleksi' => 'diterima', 'mentor_id' => $mentor->id,
            'tgl_selesai_magang' => '2026-11-24', 'kebutuhan_kampus' => ['aspek_penilaian' => $this->rubric()]]);
        $base = '/api/magang/'.$p->id;
        $this->getJson($base)->assertOk()->assertJsonPath('data.pengingat_hari', [14, 7]);
        $this->assertStringContainsString('H-14', $this->getJson($base)->json('data.pengingat'));
        $this->patchJson($base.'/pengingat', ['pengingat_hari' => [3, 2]])->assertOk()->assertJsonPath('data.pengingat', null);
        $this->patchJson($base.'/pengingat', ['pengingat_hari' => [3, 3]])->assertUnprocessable();
        $this->travelTo(now('Asia/Jakarta')->setDate(2026, 11, 24));
        $this->postJson($base.'/penilaian', $this->grades($p))->assertOk();
        $message = $this->getJson($base)->json('data.pengingat');
        $this->assertStringContainsString('sertifikat', $message);
        $this->assertStringContainsString('surat selesai', $message);
    }

    public function test_only_participant_sets_campus_requirements_and_assigned_mentor_confirms_completion(): void
    {
        $mentor = $this->staff();
        $intern = User::factory()->create(['bidang_id' => $mentor->bidang_id, 'is_magang' => true, 'is_active' => 1]);
        $p = $this->applicant(['status_seleksi' => 'diterima', 'mentor_id' => $mentor->id, 'user_id' => $intern->id]);
        $other = $this->applicant(['status_seleksi' => 'diterima']);
        $base = '/api/magang/'.$p->id;
        $statusUrl = $base.'/kampus';
        $require = ['kampus_perlu_input' => true];
        $complete = ['kampus_sudah_input' => true];

        $this->patchJson($statusUrl, $complete)->assertUnprocessable();
        $this->actingAs($intern, 'sanctum');
        $this->patchJson('/api/magang/'.$other->id.'/kampus', $require)->assertNotFound();
        $this->patchJson($statusUrl, [...$require, ...$complete])->assertUnprocessable()->assertJsonValidationErrors('kampus_sudah_input');
        $this->patchJson($statusUrl, $require)->assertOk()->assertJsonPath('data.kampus_perlu_input', true)->assertJsonPath('data.kampus_sudah_input', false);
        $this->assertStringContainsString('input atau dokumen kampus', $this->getJson($base)->json('data.pengingat'));

        $this->staff();
        $this->patchJson($statusUrl, $complete)->assertForbidden();
        $this->staff('TEST', 'admin');
        $this->patchJson($statusUrl, $require)->assertForbidden();
        $this->patchJson($statusUrl, $complete)->assertForbidden();

        $this->actingAs($mentor, 'sanctum');
        $this->patchJson($statusUrl, $require)->assertUnprocessable()->assertJsonValidationErrors('kampus_perlu_input');
        $this->patchJson($statusUrl, $complete)->assertOk()->assertJsonPath('data.kampus_perlu_input', true)->assertJsonPath('data.kampus_sudah_input', true);
        $this->assertStringNotContainsString('input atau dokumen kampus', $this->getJson($base)->json('data.pengingat'));

        $this->actingAs($intern, 'sanctum');
        $this->patchJson($statusUrl, $require)->assertOk()->assertJsonPath('data.kampus_sudah_input', true);
        $this->patchJson($statusUrl, ['kampus_perlu_input' => false])->assertOk()->assertJsonPath('data.kampus_sudah_input', false);
        $this->patchJson($statusUrl, $require)->assertOk()->assertJsonPath('data.kampus_sudah_input', false);
    }

    public function test_laptop_can_be_declared_truthfully_but_is_checked_when_accepting_an_aptika_applicant(): void
    {
        $mentor = $this->staff('APTIKA');
        $p = $this->applicant(['bidang_id' => $mentor->bidang_id, 'memiliki_laptop' => false]);
        $this->patchJson('/api/magang/'.$p->id.'/seleksi', ['status_seleksi' => 'diterima', 'jurusan_sesuai' => true])->assertUnprocessable();
        $this->patchJson('/api/magang/'.$p->id.'/seleksi', ['status_seleksi' => 'ditolak', 'catatan_seleksi' => 'Laptop belum tersedia.'])->assertOk();
    }

    public function test_account_is_created_only_once_after_mentor_approval_and_can_login(): void
    {
        $mentor = $this->staff();
        $p = $this->applicant(['email_login' => 'peserta.baru@magang.com']);
        $this->assertDatabaseMissing('users', ['email' => $p->email_login]);
        auth()->forgetGuards();
        auth()->shouldUse('web');
        $this->postJson('/api/login', ['email' => $p->email_login, 'password' => 'password'])->assertUnprocessable();
        $this->actingAs($mentor, 'sanctum');
        $this->patchJson('/api/magang/'.$p->id.'/seleksi', ['status_seleksi' => 'diterima', 'jurusan_sesuai' => true])->assertOk();
        $account = User::findOrFail($p->fresh()->user_id);
        $this->assertTrue($account->is_magang);
        $this->assertSame('user', $account->role);
        $password = $p->fresh()->password_awal;
        $this->assertFalse(Hash::check('password', $account->password));
        $this->assertTrue(Hash::check($password, $account->password));
        $this->patchJson('/api/magang/'.$p->id.'/seleksi', ['status_seleksi' => 'diterima', 'jurusan_sesuai' => true])->assertUnprocessable();
        $this->assertSame(1, User::where('email', $p->email_login)->count());
        auth()->forgetGuards();
        auth()->shouldUse('web');
        $this->postJson('/api/login', ['email' => $p->email_login, 'password' => $password])->assertOk()->assertJsonPath('user.is_magang', true)->assertJsonStructure(['access_token']);
    }

    public function test_rejected_applicant_has_no_account_and_existing_account_is_never_overwritten(): void
    {
        $mentor = $this->staff();
        $p = $this->applicant(['email_login' => 'ditolak@magang.com']);
        $this->patchJson('/api/magang/'.$p->id.'/seleksi', ['status_seleksi' => 'ditolak'])->assertOk();
        $this->assertDatabaseMissing('users', ['email' => 'ditolak@magang.com']);
        $p = $this->applicant(['email_login' => 'existing@magang.com']);
        $existing = User::factory()->create(['email' => 'existing@magang.com']);
        $hash = $existing->password;
        $this->patchJson('/api/magang/'.$p->id.'/seleksi', ['status_seleksi' => 'diterima', 'jurusan_sesuai' => true])->assertUnprocessable();
        $this->assertSame('menunggu', $p->fresh()->status_seleksi);
        $this->assertSame($hash, $existing->fresh()->password);
    }

    public function test_intern_account_has_only_two_features_and_cannot_access_other_participants_or_staff_commands(): void
    {
        $mentor = $this->staff();
        $p = $this->applicant();
        $other = $this->applicant();
        $this->patchJson('/api/magang/'.$p->id.'/seleksi', ['status_seleksi' => 'diterima', 'jurusan_sesuai' => true])->assertOk();
        $intern = User::findOrFail($p->fresh()->user_id);
        $this->actingAs($intern, 'sanctum');
        $this->getJson('/api/magang')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/magang/'.$other->id)->assertNotFound();
        $this->getJson('/api/magang/options')->assertForbidden();
        $this->getJson('/api/admin/users')->assertForbidden();
        $this->getJson('/api/smki/daftar-rekaman')->assertForbidden();
        $this->postJson('/api/magang/'.$p->id.'/penilaian', $this->grades($p))->assertForbidden();
        $this->patchJson('/api/magang/'.$other->id.'/seleksi', ['status_seleksi' => 'diterima', 'jurusan_sesuai' => true])->assertForbidden();
        $this->postJson('/api/magang/'.$p->id.'/upload-nda', ['nda_file' => UploadedFile::fake()->create('nda.pdf', 50, 'application/pdf')])->assertOk();
        $me = $this->getJson('/api/me')->assertOk()->assertJsonPath('user.is_magang', true);
        $enabled = collect($me->json('services'))->where('is_enabled', true)->pluck('code')->sort()->values()->all();
        $this->assertSame(['MAGANG', 'MANAJEMEN_TUGAS'], $enabled);
    }

    public function test_registration_documents_are_required_private_and_only_downloadable_by_authorized_users(): void
    {
        $mentor = $this->staff();
        $p = $this->applicant();
        Storage::disk('local')->put('magang-pendaftaran/test.pdf', '%PDF-test');
        $p->update(['lampiran_pendaftaran' => ['surat_pengantar' => ['path' => 'magang-pendaftaran/test.pdf', 'nama' => 'pengantar.pdf', 'label' => 'Surat pengantar']]]);
        $this->get('/api/storage/private/magang-pendaftaran/test.pdf')->assertNotFound();
        $this->get('/api/storage/%2e%2e%2fprivate%2fmagang-pendaftaran/test.pdf')->assertNotFound();
        $this->getJson('/api/magang/'.$p->id)->assertOk()->assertJsonPath('data.lampiran_pendaftaran.0.nama', 'pengantar.pdf')->assertJsonMissingPath('data.lampiran_pendaftaran.0.path');
        $this->get('/api/magang/'.$p->id.'/lampiran/surat_pengantar')->assertOk()->assertDownload('pengantar.pdf');
        $this->staff('OTHER');
        $this->get('/api/magang/'.$p->id.'/lampiran/surat_pengantar')->assertNotFound();
        auth()->forgetGuards();
        $this->getJson('/api/magang/'.$p->id.'/lampiran/surat_pengantar')->assertUnauthorized();
        $this->postJson('/api/magang', [])->assertUnprocessable()->assertJsonValidationErrors(['surat_pengantar', 'username', 'cv_magang']);
    }

    public function test_telkom_reference_keeps_45_percent_weight_and_computes_43_15_without_copying_scores(): void
    {
        $mentor = $this->staff();
        $rubric = array_map(fn ($row) => [...$row, 'skala' => '0–100'], config('magang.rubrik_telkom'));
        $p = $this->applicant(['status_seleksi' => 'diterima', 'mentor_id' => $mentor->id,
            'kebutuhan_kampus' => ['aspek_penilaian' => $rubric]]);
        $payload = $this->grades($p, ['94', '95', '97', '96', '97', '95', '95', '97']);
        $this->postJson('/api/magang/'.$p->id.'/penilaian', $payload)->assertOk();
        $this->getJson('/api/magang/'.$p->id)->assertOk()->assertJsonPath('data.ringkasan_nilai.total_bobot', 45)->assertJsonPath('data.ringkasan_nilai.total_terbobot', 43.15);
        $preset = $this->getJson('/api/magang/options')->assertOk()->json('data.rubrik_telkom');
        $this->assertCount(8, $preset);
        $this->assertArrayNotHasKey('nilai', $preset[0]);
        $payload['nilai'][0] = '101';
        $this->postJson('/api/magang/'.$p->id.'/penilaian', $payload)->assertUnprocessable();
    }

    public function test_legacy_accepted_participant_can_be_provisioned_without_repeating_selection(): void
    {
        $mentor = $this->staff();
        $p = $this->applicant(['status_seleksi' => 'diterima', 'mentor_id' => $mentor->id]);
        $this->postJson('/api/magang/'.$p->id.'/akun')->assertOk()->assertJsonPath('data.email_login', 'magang.'.$p->id.'@magang.com');
        $this->postJson('/api/magang/'.$p->id.'/akun')->assertUnprocessable();
        $pending = $this->applicant();
        $this->postJson('/api/magang/'.$pending->id.'/akun')->assertUnprocessable();
    }

    public function test_deleting_accepted_participant_revokes_account_tokens_and_removes_registration_documents(): void
    {
        $mentor = $this->staff();
        $p = $this->applicant();
        $this->patchJson('/api/magang/'.$p->id.'/seleksi', ['status_seleksi' => 'diterima', 'jurusan_sesuai' => true])->assertOk();
        $account = User::findOrFail($p->fresh()->user_id);
        $account->createToken('test-session');
        $paths = ['cv-magang/delete.pdf', 'nda-file/delete.pdf', 'sertifikat-magang/delete.pdf', 'surat-magang/delete.pdf'];
        foreach ($paths as $path) {
            Storage::disk('public')->put($path, '%PDF-test');
        }
        Storage::disk('local')->put('magang-pendaftaran/delete.pdf', '%PDF-test');
        $p->update(['cv_magang' => $paths[0], 'nda_file' => $paths[1], 'sertifikat_file' => $paths[2],
            'lampiran_pendaftaran' => ['surat_pengantar' => ['path' => 'magang-pendaftaran/delete.pdf', 'nama' => 'surat.pdf', 'label' => 'Surat']]]);
        $p->dokumens()->where('jenis', 'penerimaan')->update(['file' => $paths[3]]);
        $this->deleteJson('/api/magang/'.$p->id)->assertOk()->assertJsonPath('success', true);
        $this->assertDatabaseMissing('magangs', ['id' => $p->id]);
        $this->assertDatabaseMissing('magang_dokumens', ['magang_id' => $p->id]);
        $this->assertDatabaseMissing('users', ['id' => $account->id]);
        $this->assertSame(0, $account->tokens()->count());
        $this->assertSame(1, $mentor->fresh()->is_active);
        Storage::disk('public')->assertMissing($paths);
        Storage::disk('local')->assertMissing('magang-pendaftaran/delete.pdf');
    }

    public function test_deletion_is_available_for_pending_and_rejected_participants_but_keeps_access_boundaries(): void
    {
        $mentor = $this->staff();
        foreach (['menunggu', 'ditolak'] as $status) {
            $p = $this->applicant(['status_seleksi' => $status]);
            $this->deleteJson('/api/magang/'.$p->id)->assertOk();
        }
        $p = $this->applicant(['status_seleksi' => 'diterima', 'mentor_id' => $mentor->id]);
        $this->staff();
        $this->deleteJson('/api/magang/'.$p->id)->assertForbidden();
        $this->staff('OTHER');
        $this->deleteJson('/api/magang/'.$p->id)->assertNotFound();
        $intern = User::factory()->create(['bidang_id' => $mentor->bidang_id, 'is_active' => 1]);
        $intern->forceFill(['is_magang' => true])->save();
        $this->actingAs($intern, 'sanctum');
        $this->deleteJson('/api/magang/'.$p->id)->assertForbidden();
        $this->assertDatabaseHas('magangs', ['id' => $p->id]);
    }

    public function test_dashboard_orders_by_registration_time_with_stable_ties_not_edit_time(): void
    {
        $this->staff();
        $first = $this->applicant();
        $second = $this->applicant();
        $third = $this->applicant();
        $first->forceFill(['created_at' => '2026-11-09 10:00:00', 'updated_at' => '2026-11-10 12:00:00'])->save();
        $second->forceFill(['created_at' => '2026-11-01 10:00:00'])->save();
        $third->forceFill(['created_at' => '2026-11-09 10:00:00'])->save();
        $this->getJson('/api/magang')->assertOk()->assertJsonPath('data.0.id', $third->id)
            ->assertJsonPath('data.1.id', $first->id)->assertJsonPath('data.2.id', $second->id);
    }

    public function test_formal_photo_is_private_before_approval_and_becomes_the_created_account_avatar(): void
    {
        $mentor = $this->staff();
        $photo = UploadedFile::fake()->image('formal.jpg', 300, 400);
        $path = $photo->store('pas-foto-magang', 'local');
        $p = $this->applicant(['pas_foto' => $path, 'foto_latar' => 'merah']);
        $this->getJson('/api/magang/'.$p->id)->assertOk()->assertJsonMissingPath('data.pas_foto')
            ->assertJsonPath('data.foto_latar', 'merah');
        $this->get('/api/magang/'.$p->id.'/foto')->assertOk();
        $this->assertDatabaseMissing('users', ['email' => 'magang.'.$p->id.'@magang.com']);
        $this->patchJson('/api/magang/'.$p->id.'/seleksi', ['status_seleksi' => 'diterima', 'jurusan_sesuai' => true])->assertOk();
        $account = User::findOrFail($p->fresh()->user_id);
        $this->assertSame('avatars/magang/'.basename($path), $account->avatar);
        Storage::disk('public')->assertExists($account->avatar);
        $this->assertSame(Storage::disk('local')->get($path), Storage::disk('public')->get($account->avatar));
        $this->actingAs($account, 'sanctum');
        $this->get('/api/magang/'.$p->id.'/foto')->assertOk();
        $this->staff('OTHER');
        $this->get('/api/magang/'.$p->id.'/foto')->assertNotFound();
        auth()->forgetGuards();
        $this->getJson('/api/magang/'.$p->id.'/foto')->assertUnauthorized();
        $this->postJson('/api/magang', ['pas_foto' => UploadedFile::fake()->create('not-a-photo.pdf', 50, 'application/pdf'), 'foto_latar' => 'hijau'])
            ->assertUnprocessable()->assertJsonValidationErrors(['pas_foto', 'foto_latar']);
        $this->postJson('/api/magang', ['pas_foto' => UploadedFile::fake()->image('too-large.png')->size(2049), 'foto_latar' => 'biru'])
            ->assertUnprocessable()->assertJsonValidationErrors('pas_foto');
    }
}
