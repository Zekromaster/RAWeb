<?php

use App\Community\Enums\AwardType;
use App\Models\PlayerBadge;
use App\Models\User;
use App\Platform\Enums\UnlockMode;
use App\Platform\Events\SiteBadgeAwarded;
use Carbon\Carbon;

/**
 * @deprecated use PlayerBadge model
 */
function AddSiteAward(
    User $user,
    int $awardType,
    ?int $data = null,
    int $dataExtra = 0,
    ?Carbon $awardDate = null,
    ?int $displayOrder = null,
): PlayerBadge {
    if (!isset($displayOrder)) {
        $displayOrder = 0;
        $query = "SELECT MAX(DisplayOrder) AS MaxDisplayOrder FROM SiteAwards WHERE User = :user";
        $dbData = legacyDbFetch($query, ['user' => $user->User]);
        if (isset($dbData['MaxDisplayOrder'])) {
            $displayOrder = (int) $dbData['MaxDisplayOrder'] + 1;
        }
    }

    PlayerBadge::updateOrInsert(
        [
            'User' => $user->User,
            'user_id' => $user->id,
            'AwardType' => $awardType,
            'AwardData' => $data,
            'AwardDataExtra' => $dataExtra,
        ],
        [
            'AwardDate' => $awardDate ?? Carbon::now(),
            'DisplayOrder' => $displayOrder,
        ]
    );

    return PlayerBadge::where('User', $user->User)
        ->where('AwardType', $awardType)
        ->where('AwardData', $data)
        ->where('AwardDataExtra', $dataExtra)
        ->first();
}

function removeDuplicateGameAwards(array &$dbResult, array $gamesToDedupe, int $awardType): void
{
    foreach ($gamesToDedupe as $game) {
        $index = 0;
        foreach ($dbResult as $award) {
            if (
                isset($award['AwardData'])
                && $award['AwardData'] === $game
                && $award['AwardDataExtra'] == UnlockMode::Softcore
                && $award['AwardType'] == $awardType
            ) {
                $dbResult[$index] = "";
                break;
            }

            $index++;
        }
    }
}

function getUsersSiteAwards(string $user, bool $showHidden = false): array
{
    $dbResult = [];

    if (!isValidUsername($user)) {
        return $dbResult;
    }

    $bindings = [
        'username' => $user,
        'username2' => $user,
    ];

    $query = "
        -- game awards (mastery, beaten)
        SELECT " . unixTimestampStatement('saw.AwardDate', 'AwardedAt') . ", saw.AwardType, saw.AwardData, saw.AwardDataExtra, saw.DisplayOrder, gd.Title, c.ID AS ConsoleID, c.Name AS ConsoleName, gd.Flags, gd.ImageIcon
            FROM SiteAwards AS saw
            LEFT JOIN GameData AS gd ON ( gd.ID = saw.AwardData AND saw.AwardType IN (" . implode(',', AwardType::game()) . ") )
            LEFT JOIN Console AS c ON c.ID = gd.ConsoleID
            WHERE
                saw.AwardType IN(" . implode(',', AwardType::game()) . ")
                AND saw.User = :username
            GROUP BY saw.AwardType, saw.AwardData, saw.AwardDataExtra
        UNION
        -- non-game awards (developer contribution, ...)
        SELECT " . unixTimestampStatement('MAX(saw.AwardDate)', 'AwardedAt') . ", saw.AwardType, MAX( saw.AwardData ), saw.AwardDataExtra, saw.DisplayOrder, NULL, NULL, NULL, NULL, NULL
            FROM SiteAwards AS saw
            WHERE
                saw.AwardType NOT IN(" . implode(',', AwardType::game()) . ")
                AND saw.User = :username2
            GROUP BY saw.AwardType
        ORDER BY DisplayOrder, AwardedAt, AwardType, AwardDataExtra ASC";

    $dbResult = legacyDbFetchAll($query, $bindings)->toArray();

    // Updated way to "squash" duplicate awards to work with the new site award ordering implementation
    $softcoreBeatenGames = [];
    $hardcoreBeatenGames = [];
    $completedGames = [];
    $masteredGames = [];

    // Get a separate list of completed and mastered games
    $awardsCount = count($dbResult);
    for ($i = 0; $i < $awardsCount; $i++) {
        if ($dbResult[$i]['AwardType'] == AwardType::Mastery && $dbResult[$i]['AwardDataExtra'] == 1) {
            $masteredGames[] = $dbResult[$i]['AwardData'];
        } elseif ($dbResult[$i]['AwardType'] == AwardType::Mastery && $dbResult[$i]['AwardDataExtra'] == 0) {
            $completedGames[] = $dbResult[$i]['AwardData'];
        } elseif ($dbResult[$i]['AwardType'] == AwardType::GameBeaten && $dbResult[$i]['AwardDataExtra'] == 1) {
            $hardcoreBeatenGames[] = $dbResult[$i]['AwardData'];
        } elseif ($dbResult[$i]['AwardType'] == AwardType::GameBeaten && $dbResult[$i]['AwardDataExtra'] == 0) {
            $softcoreBeatenGames[] = $dbResult[$i]['AwardData'];
        }
    }

    // Get a single list of games both beaten hardcore and softcore
    if (!empty($hardcoreBeatenGames) && !empty($softcoreBeatenGames)) {
        $multiBeatenGames = array_intersect($hardcoreBeatenGames, $softcoreBeatenGames);
        removeDuplicateGameAwards($dbResult, $multiBeatenGames, AwardType::GameBeaten);
    }

    // Get a single list of games both completed and mastered
    if (!empty($completedGames) && !empty($masteredGames)) {
        $multiAwardGames = array_intersect($completedGames, $masteredGames);
        removeDuplicateGameAwards($dbResult, $multiAwardGames, AwardType::Mastery);
    }

    // Remove blank indexes
    $dbResult = array_values(array_filter($dbResult));

    foreach ($dbResult as &$award) {
        if ($award['ConsoleID']) {
            settype($award['AwardType'], 'integer');
            settype($award['AwardData'], 'integer');
            settype($award['AwardDataExtra'], 'integer');
            settype($award['ConsoleID'], 'integer');
        }
    }

    return $dbResult;
}

function HasPatreonBadge(string $username): bool
{
    sanitize_sql_inputs($username);

    $query = "SELECT * FROM SiteAwards AS sa "
        . "WHERE sa.AwardType = " . AwardType::PatreonSupporter . " AND sa.User = '$username'";

    $dbResult = s_mysql_query($query);

    return mysqli_num_rows($dbResult) > 0;
}

function SetPatreonSupporter(User $user, bool $enable): void
{
    $username = $user->User;

    if ($enable) {
        $badge = AddSiteAward($user, AwardType::PatreonSupporter, 0, 0);
        SiteBadgeAwarded::dispatch($badge);
        // TODO PatreonSupporterAdded::dispatch($user);
    } else {
        $query = "DELETE FROM SiteAwards WHERE User = '$username' AND AwardType = " . AwardType::PatreonSupporter;
        s_mysql_query($query);
        // TODO PatreonSupporterRemoved::dispatch($user);
    }
}

function HasCertifiedLegendBadge(string $username): bool
{
    sanitize_sql_inputs($username);

    $query = "SELECT * FROM SiteAwards AS sa "
        . "WHERE sa.AwardType = " . AwardType::CertifiedLegend . " AND sa.User = '$username'";

    $dbResult = s_mysql_query($query);

    return mysqli_num_rows($dbResult) > 0;
}

function SetCertifiedLegend(User $user, bool $enable): void
{
    $username = $user->User;

    if ($enable) {
        $badge = AddSiteAward($user, AwardType::CertifiedLegend, 0, 0);
        SiteBadgeAwarded::dispatch($badge);
    } else {
        $query = "DELETE FROM SiteAwards WHERE User = '$username' AND AwardType = " . AwardType::CertifiedLegend;
        s_mysql_query($query);
    }
}

/**
 * Gets completed and mastery award information.
 * This includes User, Game and Completed or Mastered Date.
 *
 * Results are configurable based on input parameters allowing returning data for a specific users friends
 * and selecting a specific date
 */
function getRecentProgressionAwardData(
    string $date,
    ?string $friendsOf = null,
    int $offset = 0,
    int $count = 50,
    ?int $onlyAwardType = null,
    ?int $onlyUnlockMode = null,
): array {
    // Determine the friends condition
    $friendCondAward = "";
    if ($friendsOf !== null) {
        $friendSubquery = GetFriendsSubquery($friendsOf);
        $friendCondAward = "AND saw.User IN ($friendSubquery)";
    }

    $onlyAwardTypeClause = "
        WHERE saw.AwardType IN (" . AwardType::Mastery . ", " . AwardType::GameBeaten . ")
    ";
    if ($onlyAwardType) {
        $onlyAwardTypeClause = "WHERE saw.AwardType = $onlyAwardType";
    }

    $onlyUnlockModeClause = "saw.AwardDataExtra IS NOT NULL";
    if (isset($onlyUnlockMode)) {
        $onlyUnlockModeClause = "saw.AwardDataExtra = $onlyUnlockMode";
    }

    $retVal = [];
    $query = "SELECT s.User, s.AwardedAt, s.AwardedAtUnix, s.AwardType, s.AwardData, s.AwardDataExtra, s.GameTitle, s.GameID, s.ConsoleName, s.GameIcon
        FROM (
            SELECT 
                saw.User, saw.AwardDate as AwardedAt, UNIX_TIMESTAMP(saw.AwardDate) as AwardedAtUnix, saw.AwardType, 
                saw.AwardData, saw.AwardDataExtra, gd.Title AS GameTitle, gd.ID AS GameID, c.Name AS ConsoleName, gd.ImageIcon AS GameIcon,
                ROW_NUMBER() OVER (PARTITION BY saw.User, saw.AwardData, TIMESTAMPDIFF(MINUTE, saw.AwardDate, saw2.AwardDate) ORDER BY saw.AwardType ASC) AS rn
            FROM SiteAwards AS saw
            LEFT JOIN GameData AS gd ON gd.ID = saw.AwardData
            LEFT JOIN Console AS c ON c.ID = gd.ConsoleID
            LEFT JOIN SiteAwards AS saw2 ON saw2.User = saw.User AND saw2.AwardData = saw.AwardData AND TIMESTAMPDIFF(MINUTE, saw.AwardDate, saw2.AwardDate) BETWEEN 0 AND 1
            $onlyAwardTypeClause AND saw.AwardData > 0 AND $onlyUnlockModeClause $friendCondAward
            AND saw.AwardDate BETWEEN TIMESTAMP('$date') AND DATE_ADD('$date', INTERVAL 24 * 60 * 60 - 1 SECOND)
        ) s
        WHERE s.rn = 1
        ORDER BY AwardedAt DESC
        LIMIT $offset, $count";

    $dbResult = s_mysql_query($query);
    if ($dbResult !== false) {
        while ($db_entry = mysqli_fetch_assoc($dbResult)) {
            $retVal[] = $db_entry;
        }
    }

    return $retVal;
}

/**
 * Gets the number of event awards a user has earned
 */
function getUserEventAwardCount(string $user): int
{
    $bindings = [
        'user' => $user,
        'type' => AwardType::Mastery,
        'event' => 101,
    ];

    $query = "SELECT COUNT(DISTINCT AwardData) AS TotalAwards
              FROM SiteAwards sa
              INNER JOIN GameData gd ON gd.ID = sa.AwardData
              WHERE User = :user
              AND AwardType = :type
              AND gd.ConsoleID = :event";

    $dataOut = legacyDbFetch($query, $bindings);

    return $dataOut['TotalAwards'];
}

/**
 * Retrieves a target user's site award metadata for a given game ID.
 * An array is returned with keys "beaten-softcore", "beaten-hardcore",
 * "completed", and "mastered", which contain corresponding award details.
 * If no progression awards are found, or if the target username is not provided,
 * no awards are fetched or returned.
 *
 * @return array the array of a target user's site award metadata for a given game ID
 */
function getUserGameProgressionAwards(int $gameId, string $username): array
{
    $userGameProgressionAwards = [
        'beaten-softcore' => null,
        'beaten-hardcore' => null,
        'completed' => null,
        'mastered' => null,
    ];

    $foundAwards = PlayerBadge::where('User', '=', $username)
        ->where('AwardData', '=', $gameId)
        ->get();

    foreach ($foundAwards as $award) {
        $awardExtra = $award['AwardDataExtra'];
        $awardType = $award->AwardType;

        $key = '';
        if ($awardType == AwardType::Mastery) {
            $key = $awardExtra == UnlockMode::Softcore ? 'completed' : 'mastered';
        } elseif ($awardType == AwardType::GameBeaten) {
            $key = $awardExtra == UnlockMode::Softcore ? 'beaten-softcore' : 'beaten-hardcore';
        }

        if ($key && is_null($userGameProgressionAwards[$key])) {
            $userGameProgressionAwards[$key] = $award;
        }
    }

    return $userGameProgressionAwards;
}
