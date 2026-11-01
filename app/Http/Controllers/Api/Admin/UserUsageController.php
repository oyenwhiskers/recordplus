<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiUsageLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class UserUsageController extends Controller
{
    public function index(): JsonResponse
    {
        $monthStart = Carbon::now()->startOfMonth();

        $users = User::query()
            ->withCount('documents')
            ->orderBy('name')
            ->paginate(50);

        $userIds = $users->getCollection()->pluck('id');

        $allTime = AiUsageLog::query()
            ->select([
                'user_id',
                DB::raw('count(*) as ai_calls_count'),
                DB::raw("coalesce(sum(case when status = 'success' then 1 else 0 end), 0) as ai_calls_success_count"),
                DB::raw("coalesce(sum(case when status = 'failed' then 1 else 0 end), 0) as ai_calls_failed_count"),
                DB::raw('coalesce(sum(prompt_tokens), 0) as prompt_tokens'),
                DB::raw('coalesce(sum(completion_tokens), 0) as completion_tokens'),
                DB::raw('coalesce(sum(total_tokens), 0) as total_tokens'),
            ])
            ->whereIn('user_id', $userIds)
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        $month = AiUsageLog::query()
            ->select([
                'user_id',
                DB::raw('count(*) as ai_calls_count'),
                DB::raw("coalesce(sum(case when status = 'success' then 1 else 0 end), 0) as ai_calls_success_count"),
                DB::raw("coalesce(sum(case when status = 'failed' then 1 else 0 end), 0) as ai_calls_failed_count"),
                DB::raw('coalesce(sum(prompt_tokens), 0) as prompt_tokens'),
                DB::raw('coalesce(sum(completion_tokens), 0) as completion_tokens'),
                DB::raw('coalesce(sum(total_tokens), 0) as total_tokens'),
            ])
            ->whereIn('user_id', $userIds)
            ->where('created_at', '>=', $monthStart)
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        $users->setCollection(
            $users->getCollection()->map(function (User $user) use ($allTime, $month) {
                $lifetime = $allTime->get($user->id);
                $thisMonth = $month->get($user->id);

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'ai_enabled' => $user->ai_enabled,
                    'is_admin' => $user->is_admin,
                    'documents_count' => $user->documents_count,
                    'ai_calls_count' => (int) ($lifetime->ai_calls_count ?? 0),
                    'ai_calls_success_count' => (int) ($lifetime->ai_calls_success_count ?? 0),
                    'ai_calls_failed_count' => (int) ($lifetime->ai_calls_failed_count ?? 0),
                    'prompt_tokens' => (int) ($lifetime->prompt_tokens ?? 0),
                    'completion_tokens' => (int) ($lifetime->completion_tokens ?? 0),
                    'total_tokens' => (int) ($lifetime->total_tokens ?? 0),
                    'month_ai_calls_count' => (int) ($thisMonth->ai_calls_count ?? 0),
                    'month_ai_calls_success_count' => (int) ($thisMonth->ai_calls_success_count ?? 0),
                    'month_ai_calls_failed_count' => (int) ($thisMonth->ai_calls_failed_count ?? 0),
                    'month_prompt_tokens' => (int) ($thisMonth->prompt_tokens ?? 0),
                    'month_completion_tokens' => (int) ($thisMonth->completion_tokens ?? 0),
                    'month_total_tokens' => (int) ($thisMonth->total_tokens ?? 0),
                ];
            })
        );

        return response()->json(['users' => $users]);
    }

    public function show(User $user): JsonResponse
    {
        $monthStart = Carbon::now()->startOfMonth();

        $lifetime = AiUsageLog::query()
            ->where('user_id', $user->id)
            ->selectRaw("count(*) as ai_calls_count, coalesce(sum(case when status = 'success' then 1 else 0 end), 0) as ai_calls_success_count, coalesce(sum(case when status = 'failed' then 1 else 0 end), 0) as ai_calls_failed_count, coalesce(sum(prompt_tokens), 0) as prompt_tokens, coalesce(sum(completion_tokens), 0) as completion_tokens, coalesce(sum(total_tokens), 0) as total_tokens")
            ->first();

        $thisMonth = AiUsageLog::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', $monthStart)
            ->selectRaw("count(*) as ai_calls_count, coalesce(sum(case when status = 'success' then 1 else 0 end), 0) as ai_calls_success_count, coalesce(sum(case when status = 'failed' then 1 else 0 end), 0) as ai_calls_failed_count, coalesce(sum(prompt_tokens), 0) as prompt_tokens, coalesce(sum(completion_tokens), 0) as completion_tokens, coalesce(sum(total_tokens), 0) as total_tokens")
            ->first();

        $logs = AiUsageLog::query()
            ->with(['document:id,title,original_filename'])
            ->where('user_id', $user->id)
            ->latest()
            ->limit(50)
            ->get();

        $documents = $user->documents()
            ->latest()
            ->limit(50)
            ->get([
                'id',
                'title',
                'original_filename',
                'status',
                'created_at',
            ]);

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'ai_enabled' => $user->ai_enabled,
                'is_admin' => $user->is_admin,
                'documents_count' => $user->documents()->count(),
                'ai_calls_count' => (int) ($lifetime->ai_calls_count ?? 0),
                'ai_calls_success_count' => (int) ($lifetime->ai_calls_success_count ?? 0),
                'ai_calls_failed_count' => (int) ($lifetime->ai_calls_failed_count ?? 0),
                'prompt_tokens' => (int) ($lifetime->prompt_tokens ?? 0),
                'completion_tokens' => (int) ($lifetime->completion_tokens ?? 0),
                'total_tokens' => (int) ($lifetime->total_tokens ?? 0),
                'month_ai_calls_count' => (int) ($thisMonth->ai_calls_count ?? 0),
                'month_ai_calls_success_count' => (int) ($thisMonth->ai_calls_success_count ?? 0),
                'month_ai_calls_failed_count' => (int) ($thisMonth->ai_calls_failed_count ?? 0),
                'month_prompt_tokens' => (int) ($thisMonth->prompt_tokens ?? 0),
                'month_completion_tokens' => (int) ($thisMonth->completion_tokens ?? 0),
                'month_total_tokens' => (int) ($thisMonth->total_tokens ?? 0),
            ],
            'recent_logs' => $logs,
            'recent_documents' => $documents,
        ]);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'ai_enabled' => ['required', 'boolean'],
        ]);

        $user->forceFill(['ai_enabled' => $data['ai_enabled']])->save();

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'ai_enabled' => $user->ai_enabled,
                'is_admin' => $user->is_admin,
            ],
        ]);
    }
}
