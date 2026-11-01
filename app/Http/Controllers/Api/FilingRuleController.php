<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FilingRule;
use App\Services\FilingEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FilingRuleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $profile = $request->user()->activeProfile;
        abort_unless($profile && $profile->user_id === $request->user()->id, 403);

        $rules = FilingRule::query()
            ->with('category')
            ->where('profile_id', $profile->id)
            ->orderByDesc('updated_at')
            ->get();

        return response()->json(['rules' => $rules]);
    }

    public function approve(Request $request, FilingRule $filingRule, FilingEngine $engine): JsonResponse
    {
        abort_unless($filingRule->profile->user_id === $request->user()->id, 403);

        return response()->json([
            'rule' => $engine->approveRule($filingRule)->load('category'),
        ]);
    }

    public function disable(Request $request, FilingRule $filingRule): JsonResponse
    {
        abort_unless($filingRule->profile->user_id === $request->user()->id, 403);

        $filingRule->forceFill(['is_active' => false])->save();

        return response()->json(['rule' => $filingRule->load('category')]);
    }
}
