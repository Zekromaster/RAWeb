<?php

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use LegacyApp\Community\Enums\ArticleType;
use LegacyApp\Site\Enums\Permissions;

if (!authenticateFromCookie($user, $permissions, $userDetails, Permissions::JuniorDeveloper)) {
    return back()->withErrors(__('legacy.error.permissions'));
}

$input = Validator::validate(Arr::wrap(request()->post()), [
    'user' => 'required|string|exists:mysql_legacy.UserAccounts,User',
    'leaderboard' => 'required|integer|exists:mysql_legacy.LeaderboardDef,ID',
    'reason' => 'nullable|string|max:200',
]);

$leaderboardId = (int) $input['leaderboard'];
$targetUser = $input['user'];
$reason = $input['reason'];

// Only let jr. devs remove their own entries
if ($permissions == Permissions::JuniorDeveloper && $user !== $targetUser) {
    return back()->withErrors(__('legacy.error.permissions'));
}

if (removeLeaderboardEntry($targetUser, $leaderboardId, $score)) {
    if ($targetUser !== $user) {
        $commentText = 'removed "' . $targetUser . '"s entry of "' . $score . '" from this leaderboard';
        if (!empty($reason)) {
            $commentText .= '. Reason: ' . $reason;
        }
        addArticleComment("Server", ArticleType::Leaderboard, $leaderboardId, "\"$user\" $commentText.", $user);
    }

    return back()->with('success', __('legacy.success.ok'));
}

return back()->withErrors(__('legacy.error.error'));
