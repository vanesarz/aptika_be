<?php

namespace App\Http\Middleware;

use App\Models\Board;
use Closure;
use Illuminate\Http\Request;

class RestrictMagangAccount
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->user()?->is_magang) {
            return $next($request);
        }
        $allowed = $request->is('api/me', 'api/user', 'api/logout', 'api/profile', 'api/password',
            'api/magang', 'api/magang/*', 'api/task-management/*');
        abort_unless($allowed, 403, 'Akun magang hanya dapat mengakses Magang dan Manajemen Tugas Digital.');
        // Participant accounts may read their own internship and upload their signed NDA.
        // Staff-only commands, including screening/grades/approval, remain unavailable.
        if ($request->is('api/magang', 'api/magang/*')) {
            abort_if($request->is('api/magang/options'), 403);
            $read = $request->isMethod('get') && ! $request->is('api/magang/pendaftaran-info');
            $nda = $request->isMethod('post') && $request->is('api/magang/*/upload-nda');
            abort_unless($read || $nda, 403, 'Tindakan ini hanya untuk pengelola magang.');
        }

        if ($request->is('api/task-management/boards/*') || $request->filled('board_id')) {
            $boardId = $request->route('boardId') ?? ($request->is('api/task-management/boards/*') ? $request->route('id') : null) ?? $request->input('board_id');
            if ($boardId) {
                $board = Board::findOrFail($boardId);
                $member = $board->members()->where('user_id', $request->user()->id)->where('membership_status', 'accepted')->exists();
                abort_unless($board->visibility === 'public' || (int) $board->created_by === (int) $request->user()->id || $member, 403, 'Anda belum memiliki akses ke board ini.');
            }
        }
        if ($request->isMethod('post') && $request->is('api/task-management/boards')) {
            $request->merge(['bidang_id' => $request->user()->bidang_id]);
        }

        return $next($request);
    }
}
