<?php

namespace App\Services\TaskManagement;

use App\Models\Board;
use App\Models\BoardMember;
use App\Models\Magang;
use App\Models\Notification;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    public function create(array $payload): ?Notification
    {
        try {
            return DB::transaction(function () use ($payload) {
                return Notification::create([
                    'user_id' => $payload['user_id'],
                    'board_id' => $payload['board_id'] ?? null,
                    'task_id' => $payload['task_id'] ?? null,
                    'magang_id' => $payload['magang_id'] ?? null,
                    'type' => $payload['type'],
                    'title' => $payload['title'],
                    'message' => $payload['message'],
                    'is_read' => false,
                    'created_by_user_id' => $payload['created_by_user_id'] ?? null,
                ]);
            });
        } catch (\Throwable $e) {
            Log::warning('Notification create failed', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function notifyBoardJoinRequest(Board $board, User $actor): void
    {
        $pm = $board->pm;

        if (!$pm || (int) $pm->id === (int) $actor->id) {
            return;
        }

        $this->create([
            'user_id' => $pm->id,
            'board_id' => $board->id,
            'type' => 'JOIN_REQUEST',
            'title' => 'Permintaan bergabung board',
            'message' => sprintf(
                '%s mengirim permintaan untuk bergabung ke board %s.',
                $actor->name,
                $board->name
            ),
            'created_by_user_id' => $actor->id,
        ]);
    }

    public function notifyBoardJoinApproved(
        Board $board,
        User $recipient,
        User $actor
    ): void {
        $this->create([
            'user_id' => $recipient->id,
            'board_id' => $board->id,
            'type' => 'JOIN_APPROVED',
            'title' => 'Permintaan bergabung disetujui',
            'message' => sprintf(
                '%s menyetujui permintaan bergabung Anda ke board %s.',
                $actor->name,
                $board->name
            ),
            'created_by_user_id' => $actor->id,
        ]);
    }

    public function notifyBoardJoinRejected(
        Board $board,
        User $recipient,
        User $actor
    ): void {
        $this->create([
            'user_id' => $recipient->id,
            'board_id' => $board->id,
            'type' => 'JOIN_REJECTED',
            'title' => 'Permintaan bergabung ditolak',
            'message' => sprintf(
                '%s menolak permintaan bergabung Anda ke board %s.',
                $actor->name,
                $board->name
            ),
            'created_by_user_id' => $actor->id,
        ]);
    }

    public function notifyTaskAssigned(Task $task, User $recipient): void
    {
        $actor = $task->creator ?? $task->board?->pm;

        $this->create([
            'user_id' => $recipient->id,
            'board_id' => $task->board_id,
            'task_id' => $task->id,
            'type' => 'TASK_ASSIGNED',
            'title' => 'Tugas baru ditugaskan',
            'message' => sprintf(
                '%s menugaskan Anda untuk tugas %s.',
                $actor?->name ?? 'Sistem',
                $task->title
            ),
            'created_by_user_id' => $actor?->id ?? null,
        ]);
    }

    public function notifyTaskUpdated(
        Task $task,
        User $actor,
        array $recipients
    ): void {
        foreach ($recipients as $recipient) {
            if ((int) $recipient->id === (int) $actor->id) {
                continue;
            }

            $this->create([
                'user_id' => $recipient->id,
                'board_id' => $task->board_id,
                'task_id' => $task->id,
                'type' => 'TASK_UPDATED',
                'title' => 'Tugas diperbarui',
                'message' => sprintf(
                    '%s memperbarui tugas %s.',
                    $actor->name,
                    $task->title
                ),
                'created_by_user_id' => $actor->id,
            ]);
        }
    }

    public function notifyTaskComment(
        Task $task,
        User $actor,
        array $recipients
    ): void {
        foreach ($recipients as $recipient) {
            if ((int) $recipient->id === (int) $actor->id) {
                continue;
            }

            $this->create([
                'user_id' => $recipient->id,
                'board_id' => $task->board_id,
                'task_id' => $task->id,
                'type' => 'TASK_COMMENT',
                'title' => 'Komentar baru',
                'message' => sprintf(
                    '%s mengomentari tugas %s.',
                    $actor->name,
                    $task->title
                ),
                'created_by_user_id' => $actor->id,
            ]);
        }
    }

    public function notifyStatusChanged(
        Task $task,
        array $recipients
    ): void {
        foreach ($recipients as $recipient) {
            $this->create([
                'user_id' => $recipient->id,
                'board_id' => $task->board_id,
                'task_id' => $task->id,
                'type' => 'TASK_STATUS_CHANGED',
                'title' => 'Status tugas berubah',
                'message' => sprintf(
                    'Status tugas %s berubah.',
                    $task->title
                ),
                'created_by_user_id' => $task->created_by,
            ]);
        }
    }

    public function notifyBoardArchived(
        Board $board,
        array $recipients
    ): void {
        foreach ($recipients as $recipient) {
            $this->create([
                'user_id' => $recipient->id,
                'board_id' => $board->id,
                'type' => 'BOARD_ARCHIVED',
                'title' => 'Board diarsipkan',
                'message' => sprintf(
                    'Board %s telah diarsipkan.',
                    $board->name
                ),
                'created_by_user_id' => $board->created_by,
            ]);
        }
    }

    public function notifyDueSoon(
        Task $task,
        User $recipient
    ): void {
        $this->create([
            'user_id' => $recipient->id,
            'board_id' => $task->board_id,
            'task_id' => $task->id,
            'type' => 'TASK_DUE_SOON',
            'title' => 'Tugas akan jatuh tempo',
            'message' => sprintf(
                'Tugas %s akan jatuh tempo besok.',
                $task->title
            ),
            'created_by_user_id' => $task->created_by,
        ]);
    }

    public function notifyMagangNewApplicant(Magang $magang): void
    {
        foreach ($this->aptikaAdmins() as $recipient) {
            $this->create([
                'user_id' => $recipient->id,
                'magang_id' => $magang->id,
                'type' => 'MAGANG_NEW_APPLICANT',
                'title' => 'Pendaftar magang baru',
                'message' => sprintf('%s mendaftar program magang.', $magang->nama),
            ]);
        }
    }

    public function notifyMagangMentorAssigned(Magang $magang, User $actor): void
    {
        if (! $magang->mentor_id || (int) $magang->mentor_id === (int) $actor->id) {
            return;
        }

        $this->create([
            'user_id' => $magang->mentor_id,
            'magang_id' => $magang->id,
            'type' => 'MAGANG_MENTOR_ASSIGNED',
            'title' => 'Peserta magang baru',
            'message' => sprintf('Anda ditugaskan menjadi mentor untuk %s.', $magang->nama),
            'created_by_user_id' => $actor->id,
        ]);
    }

    public function notifyMagangRatingPending(Magang $magang): void
    {
        $recipients = $this->aptikaAdmins();
        if ($mentor = $magang->mentor) {
            $recipients->push($mentor);
        }

        foreach ($recipients->unique('id') as $recipient) {
            $alreadyNotified = Notification::query()
                ->where('user_id', $recipient->id)
                ->where('magang_id', $magang->id)
                ->where('type', 'MAGANG_RATING_PENDING')
                ->exists();
            if ($alreadyNotified) {
                continue;
            }

            $this->create([
                'user_id' => $recipient->id,
                'magang_id' => $magang->id,
                'type' => 'MAGANG_RATING_PENDING',
                'title' => 'Penilaian magang menunggu',
                'message' => sprintf('Periode magang %s telah berakhir dan penilaian masih perlu diisi.', $magang->nama),
            ]);
        }
    }

    public function notifyMagangRatingSubmitted(Magang $magang, User $actor): void
    {
        if (! $magang->user_id) {
            return;
        }

        $this->create([
            'user_id' => $magang->user_id,
            'magang_id' => $magang->id,
            'type' => 'MAGANG_RATING_SUBMITTED',
            'title' => 'Penilaian magang selesai',
            'message' => sprintf('%s telah mengisi penilaian magang Anda.', $actor->name),
            'created_by_user_id' => $actor->id,
        ]);
    }

    public function notifyMagangCampusGuidanceChanged(Magang $magang, User $actor): void
    {
        if (! $magang->mentor_id || (int) $magang->mentor_id === (int) $actor->id) {
            return;
        }

        $this->create([
            'user_id' => $magang->mentor_id,
            'magang_id' => $magang->id,
            'type' => 'MAGANG_GUIDANCE_UPDATED',
            'title' => 'Informasi kewajiban kampus diperbarui',
            'message' => sprintf('%s memperbarui tautan, panduan, atau dokumen penilaian kampus.', $magang->nama),
            'created_by_user_id' => $actor->id,
        ]);
    }

    public function notifyMagangCertificateFinalized(Magang $magang, User $actor): void
    {
        if (! $magang->user_id) {
            return;
        }

        $this->create([
            'user_id' => $magang->user_id,
            'magang_id' => $magang->id,
            'type' => 'MAGANG_CERTIFICATE_FINALIZED',
            'title' => 'Sertifikat magang disahkan',
            'message' => sprintf('Sertifikat magang Anda telah disahkan dengan nomor %s.', $magang->nomor_sertifikat),
            'created_by_user_id' => $actor->id,
        ]);
    }

    private function aptikaAdmins(): Collection
    {
        return User::query()
            ->where('role', 'admin')
            ->whereHas('bidang', fn ($query) => $query->where('code', 'APTIKA'))
            ->get();
    }

    public function getForUser(
        int $userId,
        int $limit = 15,
        int $offset = 0
    ): Collection {
        return Notification::query()
            ->where('user_id', $userId)
            ->with([
                'board',
                'task',
                'magang',
                'createdBy',
                'user',
            ])
            ->orderByDesc('created_at')
            ->skip($offset)
            ->limit($limit)
            ->get();
    }

    public function unreadCount(int $userId): int
    {
        return Notification::query()
            ->where('user_id', $userId)
            ->where('is_read', false)
            ->count();
    }

    public function markAsRead(
        int $notificationId,
        int $userId
    ): bool {
        $notification = Notification::where('id', $notificationId)
            ->where('user_id', $userId)
            ->first();

        if (!$notification) {
            return false;
        }

        $notification->update([
            'is_read' => true,
            'read_at' => now(),
        ]);

        return true;
    }

    public function markAllAsRead(int $userId): int
    {
        return Notification::where('user_id', $userId)
            ->where('is_read', false)
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);
    }

    public function delete(
        int $notificationId,
        int $userId
    ): bool {
        $notification = Notification::where('id', $notificationId)
            ->where('user_id', $userId)
            ->first();

        if (!$notification) {
            return false;
        }

        $notification->delete();

        return true;
    }
}