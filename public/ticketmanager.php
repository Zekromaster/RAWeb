<?php

use RA\ArticleType;
use RA\Permissions;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/bootstrap.php';

if (!RA_ReadCookieCredentials($user, $points, $truePoints, $unreadMessageCount, $permissions)) {
    header("Location: " . getenv('APP_URL'));
    exit;
}

$maxCount = 100;
$count = requestInputSanitized('c', $maxCount, 'integer');
$offset = requestInputSanitized('o', 0, 'integer');

$ticketID = requestInputSanitized('i', 0, 'integer');
$defaultFilter = 131065; // 131065 sets all filters active except for Closed Resolved and Not Achievement Developer
$allTicketsFilter = 131071; // const
$ticketFilters = requestInputSanitized('t', $defaultFilter, 'integer');

$reportStates = ["Closed", "Open", "Resolved"];
$reportModes = ["Softcore", "Hardcore"];

$altTicketData = null;
$commentData = null;
$filteredTicketsCount = null;
$numArticleComments = null;
$numClosedTickets = null;
$numOpenTickets = null;
$ticketData = null;
if ($ticketID != 0) {
    $ticketData = getTicket($ticketID);
    if ($ticketData == false) {
        $ticketID = 0;
        $errorCode = 'notfound';
    }

    $action = requestInputSanitized('action', null);
    $reason = null;
    $ticketState = 1;
    switch ($action) {
        case "closed-mistaken":
            $ticketState = 0;
            $reason = "Mistaken report";
            break;

        case "resolved":
            if ($permissions >= Permissions::Developer) {
                $ticketState = 2;
            }
            break;

        case "demoted":
            if ($permissions >= Permissions::Developer) {
                $ticketState = 0;
                $reason = "Demoted";
            }
            break;

        case "not-enough-info":
            if ($permissions >= Permissions::Developer) {
                $ticketState = 0;
                $reason = "Not enough information";
            }
            break;

        case "wrong-rom":
            if ($permissions >= Permissions::Developer) {
                $ticketState = 0;
                $reason = "Wrong ROM";
            }
            break;

        case "network":
            if ($permissions >= Permissions::Developer) {
                $ticketState = 0;
                $reason = "Network problems";
            }
            break;

        case "unable-to-reproduce":
            if ($permissions >= Permissions::Developer) {
                $ticketState = 0;
                $reason = "Unable to reproduce";
            }
            break;

        case "closed-other":
            if ($permissions >= Permissions::Developer) {
                $ticketState = 0;
                $reason = "See the comments";
            }
            break;

        case "reopen":
            $ticketState = 1;
            break;

        default:
            $action = null;
            break;
    }

    if ($action != null &&
        $ticketState != $ticketData['ReportState'] &&
        (
            $permissions >= Permissions::Developer ||
            $user == $ticketData['ReportedBy']
        )
    ) {
        updateTicket($user, $ticketID, $ticketState, $reason);
        $ticketData = getTicket($ticketID);
    }

    $numArticleComments = getArticleComments(7, $ticketID, 0, 20, $commentData);

    // sets all filters enabled so we get closed/resolved tickets as well
    $altTicketData = getAllTickets(0, 99, null, null, null, null, null, $ticketData['AchievementID'], $allTicketsFilter);
    // var_dump($altTicketData);
    $numOpenTickets = 0;
    foreach ($altTicketData as $pastTicket) {
        settype($pastTicket["ID"], 'integer');

        if ($pastTicket["ReportState"] == 1 && $pastTicket["ID"] !== $ticketID) {
            $numOpenTickets++;
        }
    }

    $numClosedTickets = ((is_countable($altTicketData) ? count($altTicketData) : 0) - $numOpenTickets) - 1;
}

$assignedToUser = null;
$reportedByUser = null;
$resolvedByUser = null;
$gamesTableFlag = 0;
$gameIDGiven = 0;
if ($ticketID == 0) {
    $gamesTableFlag = requestInputSanitized('f', null, 'integer');
    if ($gamesTableFlag == 1) {
        $count = requestInputSanitized('c', 100, 'integer');
        $ticketData = gamesSortedByOpenTickets($count);
    } else {
        $assignedToUser = requestInputSanitized('u', null);
        if (!isValidUsername($assignedToUser)) {
            $assignedToUser = null;
        }
        $reportedByUser = requestInputSanitized('p', null);
        if (!isValidUsername($reportedByUser)) {
            $reportedByUser = null;
        }
        $resolvedByUser = requestInputSanitized('r', null);
        if (!isValidUsername($resolvedByUser)) {
            $resolvedByUser = null;
        }
        $gameIDGiven = requestInputSanitized('g', null, 'integer');

        $achievementIDGiven = requestInputSanitized('a', null, 'integer');
        if ($achievementIDGiven > 0) {
            $achievementData = GetAchievementData($achievementIDGiven);
            $achievementTitle = $achievementData['Title'];
            $gameIDGiven = $achievementData['GameID']; // overwrite the given game ID
        }

        if ($gamesTableFlag == 5) {
            $ticketData = getAllTickets($offset, $count, $assignedToUser, $reportedByUser, $resolvedByUser, $gameIDGiven, $achievementIDGiven, $ticketFilters, true);
        } else {
            $ticketData = getAllTickets($offset, $count, $assignedToUser, $reportedByUser, $resolvedByUser, $gameIDGiven, $achievementIDGiven, $ticketFilters);
        }
    }
}

if (!empty($gameIDGiven)) {
    getGameTitleFromID($gameIDGiven, $gameTitle, $consoleID, $consoleName, $forumTopic, $gameData);
}

sanitize_outputs(
    $achievementTitle,
    $gameTitle,
    $consoleName,
);

$pageTitle = "Ticket Manager";

$errorCode = requestInputSanitized('e');
RenderHtmlStart();
RenderHtmlHead($pageTitle);
?>
<body>
<?php RenderTitleBar($user, $points, $truePoints, $unreadMessageCount, $errorCode, $permissions); ?>
<?php RenderToolbar($user, $permissions); ?>
<div id="mainpage">
    <?php RenderErrorCodeWarning($errorCode); ?>
    <div id="fullcontainer">
        <?php
        echo "<div class='navpath'>";
        if ($gamesTableFlag == 1) {
            echo "<a href='/ticketmanager.php'>$pageTitle</a></b> &raquo; <b>Games With Open Tickets";
        } else {
            if ($ticketID == 0) {
                echo "<a href='/ticketmanager.php'>$pageTitle</a>";
                if (!empty($assignedToUser)) {
                    echo " &raquo; <a href='/user/$assignedToUser'>$assignedToUser</a>";
                }
                if (!empty($reportedByUser)) {
                    echo " &raquo; <a href='/user/$reportedByUser'>$reportedByUser</a>";
                }
                if (!empty($resolvedByUser)) {
                    echo " &raquo; <a href='/user/$resolvedByUser'>$resolvedByUser</a>";
                }
                if (!empty($gameIDGiven)) {
                    echo " &raquo; <a href='/ticketmanager.php?g=$gameIDGiven'>$gameTitle ($consoleName)</a>";
                    if (!empty($achievementIDGiven)) {
                        echo " &raquo; $achievementTitle";
                    }
                }
            } else {
                echo "<a href='/ticketmanager.php'>$pageTitle</a>";
                echo " &raquo; <b>Inspect Ticket</b>";
            }
        }
        echo "</div>";

        if ($gamesTableFlag == 1) {
            echo "<h3>Top " . (is_countable($ticketData) ? count($ticketData) : 0) . " Games Sorted By Most Outstanding Tickets</h3>";
        } else {
            $assignedToUser = requestInputSanitized('u', null);
            if (!isValidUsername($assignedToUser)) {
                $assignedToUser = null;
            }
            $reportedByUser = requestInputSanitized('p', null);
            if (!isValidUsername($reportedByUser)) {
                $reportedByUser = null;
            }
            $resolvedByUser = requestInputSanitized('r', null);
            if (!isValidUsername($resolvedByUser)) {
                $resolvedByUser = null;
            }
            if ($gamesTableFlag == 5) {
                $openTicketsCount = countOpenTickets(true);
                $filteredTicketsCount = countOpenTickets(true, $ticketFilters, $assignedToUser, $reportedByUser, $resolvedByUser, $gameIDGiven);
                if ($ticketID == 0) {
                    echo "<h3 class='longheader'>$pageTitle - " . $openTicketsCount . " Open Unofficial Ticket" . ($openTicketsCount == 1 ? "" : "s") . "</h3>";
                } else {
                    echo "<h3 class='longheader'>Inspect Ticket</h3>";
                }
            } else {
                $openTicketsCount = countOpenTickets();
                $filteredTicketsCount = countOpenTickets(false, $ticketFilters, $assignedToUser, $reportedByUser, $resolvedByUser, $gameIDGiven);
                if ($ticketID == 0) {
                    echo "<h3 class='longheader'>$pageTitle - " . $openTicketsCount . " Open Ticket" . ($openTicketsCount == 1 ? "" : "s") . "</h3>";
                } else {
                    echo "<h3 class='longheader'>Inspect Ticket</h3>";
                }
            }
        }

        echo "<div class='detaillist'>";

        if ($gamesTableFlag == 1) {
            echo "<p><b>If you're a developer and find games that you love in this list, consider helping to resolve their tickets.</b></p>";
            echo "<table><tbody>";

            echo "<th>Game</th>";
            echo "<th>Number of Open Tickets</th>";

            $rowCount = 0;

            foreach ($ticketData as $nextTicket) {
                $gameID = $nextTicket['GameID'];
                $gameTitle = $nextTicket['GameTitle'];
                $gameBadge = $nextTicket['GameIcon'];
                $consoleName = $nextTicket['Console'];
                $openTickets = $nextTicket['OpenTickets'];

                sanitize_outputs(
                    $gameTitle,
                    $consoleName,
                );

                if ($rowCount++ % 2 == 0) {
                    echo "<tr>";
                } else {
                    echo "<tr>";
                }

                echo "<td>";
                echo GetGameAndTooltipDiv($gameID, $gameTitle, $gameBadge, $consoleName);
                echo "</td>";
                echo "<td><a href='/ticketmanager.php?g=$gameID'>$openTickets</a></td>";

                echo "</tr>";
            }
            echo "</tbody></table>";
        } else {
            if ($ticketID == 0) {
                echo "<h4>Filters - " . $filteredTicketsCount . " Ticket" . ($filteredTicketsCount == 1 ? "" : "s") . " Filtered</h4>";
                echo "<div class='embedded mb-1'>";

                /*
                    Each filter is represented by a bit in the $ticketFilters variable.
                    This allows us to easily determine which filters are active as well as toggle them back and forth.
                 */
                $openTickets = ($ticketFilters & (1 << 0));
                $closedTickets = ($ticketFilters & (1 << 1));
                $resolvedTickets = ($ticketFilters & (1 << 2));
                $triggeredTickets = ($ticketFilters & (1 << 3));
                $didNotTriggerTickets = ($ticketFilters & (1 << 4));
                $hashKnownTickets = ($ticketFilters & (1 << 5));
                $hashUnknownTickets = ($ticketFilters & (1 << 6));
                $raEmulatorTickets = ($ticketFilters & (1 << 7));
                $rarchKnownTickets = ($ticketFilters & (1 << 8));
                $rarchUnknownTickets = ($ticketFilters & (1 << 9));
                $emulatorUnknownTickets = ($ticketFilters & (1 << 10));
                $modeUnknown = ($ticketFilters & (1 << 11));
                $modeHardcore = ($ticketFilters & (1 << 12));
                $modeSoftcore = ($ticketFilters & (1 << 13));
                $devInactive = ($ticketFilters & (1 << 14));
                $devActive = ($ticketFilters & (1 << 15));
                $devJunior = ($ticketFilters & (1 << 16));
                $notAchievementDeveloper = ($ticketFilters & (1 << 17));

                sanitize_outputs($assignedToUser, $reportedByUser, $resolvedByUser);

                // State Filters
                echo "<div>";
                echo "<b>Ticket State:</b> ";
                $standardFilterUrl = "/ticketmanager.php?g=$gameIDGiven&u=$assignedToUser&p=$reportedByUser&r=$resolvedByUser&f=$gamesTableFlag&t=";
                $coreFilterUrl = "/ticketmanager.php?g=$gameIDGiven&u=$assignedToUser&p=$reportedByUser&r=$resolvedByUser&f=3&t=";
                $unofficialFilterUrl = "/ticketmanager.php?g=$gameIDGiven&u=$assignedToUser&p=$reportedByUser&r=$resolvedByUser&f=5&t=";
                $noResolverFilterUrl = "/ticketmanager.php?g=$gameIDGiven&u=$assignedToUser&p=$reportedByUser&r=&f=$gamesTableFlag&t=";

                if ($openTickets) {
                    echo "<b><a href='$standardFilterUrl" . ($ticketFilters & ~(1 << 0)) . "'>*Open</a></b> | ";
                } else {
                    echo "<a href='$standardFilterUrl" . ($ticketFilters | (1 << 0)) . "'>Open</a> | ";
                }

                if ($closedTickets) {
                    if ($resolvedTickets) {
                        echo "<b><a href='$standardFilterUrl" . ($ticketFilters & ~(1 << 1)) . "'>*Closed</a></b> | ";
                    } else {
                        echo "<b><a href='$noResolverFilterUrl" . ($ticketFilters & ~(1 << 1) & ~(1 << 17)) . "'>*Closed</a></b> | ";
                    }
                } else {
                    echo "<a href='$standardFilterUrl" . ($ticketFilters | (1 << 1)) . "'>Closed</a> | ";
                }

                if ($resolvedTickets) {
                    if ($closedTickets) {
                        echo "<b><a href='$standardFilterUrl" . ($ticketFilters & ~(1 << 2)) . "'>*Resolved</a></b>";
                    } else {
                        echo "<b><a href='$noResolverFilterUrl" . ($ticketFilters & ~(1 << 2) & ~(1 << 17)) . "'>*Resolved</a></b>";
                    }
                } else {
                    echo "<a href='$standardFilterUrl" . ($ticketFilters | (1 << 2)) . "'>Resolved</a>";
                }
                echo "</div>";

                // Report Type Filters
                echo "<div>";
                echo "<b>Report Type:</b> ";
                if ($triggeredTickets) {
                    echo "<b><a href='$standardFilterUrl" . ($ticketFilters & ~(1 << 3)) . "'>*Triggered at wrong time</a></b> | ";
                } else {
                    echo "<a href='$standardFilterUrl" . ($ticketFilters | (1 << 3)) . "'>Triggered at wrong time</a> | ";
                }

                if ($didNotTriggerTickets) {
                    echo "<b><a href='$standardFilterUrl" . ($ticketFilters & ~(1 << 4)) . "'>*Doesn't Trigger</a></b>";
                } else {
                    echo "<a href='$standardFilterUrl" . ($ticketFilters | (1 << 4)) . "'>Doesn't Trigger</a>";
                }
                echo "</div>";

                // Hash Filters
                echo "<div>";
                echo "<b>Hash:</b> ";
                if ($hashKnownTickets) {
                    echo "<b><a href='$standardFilterUrl" . ($ticketFilters & ~(1 << 5)) . "'>*Contains Hash</a></b> | ";
                } else {
                    echo "<a href='$standardFilterUrl" . ($ticketFilters | (1 << 5)) . "'>Contains Hash</a> | ";
                }

                if ($hashUnknownTickets) {
                    echo "<b><a href='$standardFilterUrl" . ($ticketFilters & ~(1 << 6)) . "'>*Hash Unknown</a></b>";
                } else {
                    echo "<a href='$standardFilterUrl" . ($ticketFilters | (1 << 6)) . "'>Hash Unknown</a>";
                }
                echo "</div>";

                // Emulator Filters
                echo "<div>";
                echo "<b>Emulator:</b> ";
                if ($raEmulatorTickets) {
                    echo "<b><a href='$standardFilterUrl" . ($ticketFilters & ~(1 << 7)) . "'>*RA Emulator</a></b> | ";
                } else {
                    echo "<a href='$standardFilterUrl" . ($ticketFilters | (1 << 7)) . "'>RA Emulator</a> | ";
                }

                if ($rarchKnownTickets) {
                    echo "<b><a href='$standardFilterUrl" . ($ticketFilters & ~(1 << 8)) . "'>*RetroArch - Defined</a></b> | ";
                } else {
                    echo "<a href='$standardFilterUrl" . ($ticketFilters | (1 << 8)) . "'>RetroArch - Defined</a> | ";
                }

                if ($rarchUnknownTickets) {
                    echo "<b><a href='$standardFilterUrl" . ($ticketFilters & ~(1 << 9)) . "'>*RetroArch - Undefined</a></b> | ";
                } else {
                    echo "<a href='$standardFilterUrl" . ($ticketFilters | (1 << 9)) . "'>RetroArch - Undefined</a> | ";
                }

                if ($emulatorUnknownTickets) {
                    echo "<b><a href='$standardFilterUrl" . ($ticketFilters & ~(1 << 10)) . "'>*Emulator Unknown</a></b>";
                } else {
                    echo "<a href='$standardFilterUrl" . ($ticketFilters | (1 << 10)) . "'>Emulator Unknown</a>";
                }
                echo "</div>";

                // Core/Unofficial Filters - These filters are mutually exclusive
                echo "<div>";
                echo "<b>Achievement State:</b> ";

                if ($gamesTableFlag != 5) {
                    echo "<b><a href=$coreFilterUrl" . "$ticketFilters'>*Core</a></b> | ";
                } else {
                    echo "<a href=$coreFilterUrl" . "$ticketFilters'>Core</a> | ";
                }

                if ($gamesTableFlag == 5) {
                    echo "<b><a href=$unofficialFilterUrl" . "$ticketFilters'>*Unofficial</a></b>";
                } else {
                    echo "<a href=$unofficialFilterUrl" . "$ticketFilters'>Unofficial</a>";
                }
                echo "</div>";

                // Mode Filters
                echo "<div>";
                echo "<b>Mode:</b> ";

                if ($modeUnknown) {
                    echo "<b><a href='$standardFilterUrl" . ($ticketFilters & ~(1 << 11)) . "'>*Unknown</a></b> | ";
                } else {
                    echo "<a href='$standardFilterUrl" . ($ticketFilters | (1 << 11)) . "'>Unknown</a> | ";
                }
                if ($modeHardcore) {
                    echo "<b><a href='$standardFilterUrl" . ($ticketFilters & ~(1 << 12)) . "'>*Hardcore</a></b> | ";
                } else {
                    echo "<a href='$standardFilterUrl" . ($ticketFilters | (1 << 12)) . "'>Hardcore</a> | ";
                }
                if ($modeSoftcore) {
                    echo "<b><a href='$standardFilterUrl" . ($ticketFilters & ~(1 << 13)) . "'>*Softcore</a></b>";
                } else {
                    echo "<a href='$standardFilterUrl" . ($ticketFilters | (1 << 13)) . "'>Softcore</a>";
                }
                echo "</div>";

                // Active Dev Filters
                echo "<div>";
                echo "<b>Dev Status:</b> ";

                if ($devInactive) {
                    echo "<b><a href='$standardFilterUrl" . ($ticketFilters & ~(1 << 14)) . "'>*Inactive</a></b> | ";
                } else {
                    echo "<a href='$standardFilterUrl" . ($ticketFilters | (1 << 14)) . "'>Inactive</a> | ";
                }
                if ($devActive) {
                    echo "<b><a href='$standardFilterUrl" . ($ticketFilters & ~(1 << 15)) . "'>*Active</a></b> | ";
                } else {
                    echo "<a href='$standardFilterUrl" . ($ticketFilters | (1 << 15)) . "'>Active</a> | ";
                }
                if ($devJunior) {
                    echo "<b><a href='$standardFilterUrl" . ($ticketFilters & ~(1 << 16)) . "'>*Junior</a></b>";
                } else {
                    echo "<a href='$standardFilterUrl" . ($ticketFilters | (1 << 16)) . "'>Junior</a>";
                }
                echo "</div>";

                // Resolved By Filter
                if ($closedTickets || $resolvedTickets) {
                    echo "<div>";
                    echo "<b>Resolved By:</b> ";

                    if ($notAchievementDeveloper) {
                        echo "<b><a href='$standardFilterUrl" . ($ticketFilters & ~(1 << 17)) . "'>*Not Achievement Developer</a></b> ";
                    } else {
                        echo "<a href='$standardFilterUrl" . ($ticketFilters | (1 << 17)) . "'>Not Achievement Developer</a> ";
                    }
                    echo "</div>";
                }

                // Clear Filter
                if ($ticketFilters != $defaultFilter || $gamesTableFlag == 5) {
                    echo "<div>";
                    echo "<a href='$coreFilterUrl" . $defaultFilter . "'>Clear Filter</a>";
                    echo "</div>";
                }
                echo "</div>";

                if (isset($user) || !empty($assignedToUser)) {
                    $noDevFilterUrl = "/ticketmanager.php?g=$gameIDGiven&u=&p=$reportedByUser&r=$resolvedByUser&f=$gamesTableFlag&t=";
                    $devFilterUrl = "/ticketmanager.php?g=$gameIDGiven&u=$user&p=$reportedByUser&r=$resolvedByUser&f=$gamesTableFlag&t=";
                    echo "<form class='form-inline' action='$noDevFilterUrl" . "$ticketFilters' method='POST'>";

                    echo "<p><b>Developer:</b> ";
                    if (isset($user)) {
                        if ($assignedToUser == $user) {
                            echo "<b>$user</b> | ";
                        } else {
                            echo "<a href='$devFilterUrl" . "$ticketFilters'>$user</a> | ";
                        }
                    }

                    if (!empty($assignedToUser) && $assignedToUser !== $user) {
                        echo "<b>$assignedToUser</b> | ";
                    }

                    if (!empty($assignedToUser)) {
                        echo "<a href='$noDevFilterUrl" . "$ticketFilters'>Clear Filter </a>";
                    } else {
                        echo "<b>Clear Filter </b>";
                    }

                    echo "<input size='28' name='u' type='text' value=''>";
                    echo "&nbsp";
                    echo "<input type='submit' value='Select'>";
                    echo "</p>";
                    echo "</form>";
                }

                if (isset($user) || !empty($reportedByUser)) {
                    $noReporterFilterUrl = "/ticketmanager.php?g=$gameIDGiven&u=$assignedToUser&p=&r=$resolvedByUser&f=$gamesTableFlag&t=";
                    $reporterFilterUrl = "/ticketmanager.php?g=$gameIDGiven&u=$assignedToUser&p=$user&r=$resolvedByUser&f=$gamesTableFlag&t=";
                    echo "<form action='$noReporterFilterUrl" . "$ticketFilters' method='POST'>";
                    echo "<p><b>Reporter:</b> ";
                    if (isset($user)) {
                        if ($reportedByUser == $user) {
                            echo "<b>$user</b> | ";
                        } else {
                            echo "<a href='$reporterFilterUrl" . "$ticketFilters'>$user</a> | ";
                        }
                    }

                    if (!empty($reportedByUser) && $reportedByUser !== $user) {
                        echo "<b>$reportedByUser</b> | ";
                    }

                    if (!empty($reportedByUser)) {
                        echo "<a href='$noReporterFilterUrl" . "$ticketFilters'>Clear Filter </a>";
                    } else {
                        echo "<b>Clear Filter </b>";
                    }
                    echo "<input size='28' name='p' type='text' value=''>";
                    echo "&nbsp";
                    echo "<input type='submit' value='Select'>";
                    echo "</p>";
                    echo "</form>";
                }

                if ($closedTickets || $resolvedTickets) {
                    $resolverFilterUrl = "/ticketmanager.php?g=$gameIDGiven&u=$assignedToUser&p=$reportedByUser&r=$user&f=$gamesTableFlag&t=";
                    if (isset($user) || !empty($resolvedByUser)) {
                        echo "<form action='$noResolverFilterUrl" . "$ticketFilters' method='POST'>";
                        echo "<p><b>Resolver:</b> ";
                        if (isset($user)) {
                            if ($resolvedByUser == $user) {
                                echo "<b>$user</b> | ";
                            } else {
                                echo "<a href='$resolverFilterUrl" . "$ticketFilters'>$user</a> | ";
                            }
                        }

                        if (!empty($resolvedByUser) && $resolvedByUser !== $user) {
                            echo "<b>$resolvedByUser</b> | ";
                        }

                        if (!empty($resolvedByUser)) {
                            echo "<a href='$noResolverFilterUrl" . "$ticketFilters'>Clear Filter </a>";
                        } else {
                            echo "<b>Clear Filter </b>";
                        }
                        echo "<input size='28' name='r' type='text' value=''>";
                        echo "&nbsp";
                        echo "<input type='submit' value='Select'>";
                        echo "</p>";
                        echo "</form>";
                    }
                }

                if (!empty($gameIDGiven)) {
                    $noGameFilterUrl = "/ticketmanager.php?g=&u=$assignedToUser&p=$reportedByUser&r=$resolvedByUser&f=$gamesTableFlag&t=";
                    echo "<p><b>Game</b>";
                    if (!empty($achievementIDGiven)) {
                        echo "<b>/Achievement</b>: ";
                        echo "<a href='/ticketmanager.php?g=$gameIDGiven'>$gameTitle ($consoleName)</a>";
                        echo " | <b>$achievementTitle</b>";
                    } else {
                        echo ": <b>$gameTitle ($consoleName)</b>";
                    }
                    echo " | <a href='$noGameFilterUrl" . "$ticketFilters'>Clear Filter</a></p>";
                }

                echo "<table><tbody>";

                echo "<th>ID</th>";
                echo "<th>State</th>";
                echo "<th>Achievement</th>";
                echo "<th>Game</th>";
                echo "<th>Developer</th>";
                echo "<th>Reporter</th>";
                if ($closedTickets || $resolvedTickets) {
                    echo "<th>Resolver</th>";
                }
                echo "<th class='text-nowrap'>Reported At</th>";

                $rowCount = 0;

                foreach ($ticketData as $nextTicket) {
                    $ticketID = $nextTicket['ID'];
                    $achID = $nextTicket['AchievementID'];
                    $achTitle = $nextTicket['AchievementTitle'];
                    $achDesc = $nextTicket['AchievementDesc'];
                    $achAuthor = $nextTicket['AchievementAuthor'];
                    $achPoints = $nextTicket['Points'];
                    $achBadgeName = $nextTicket['BadgeName'];
                    $gameID = $nextTicket['GameID'];
                    $gameTitle = $nextTicket['GameTitle'];
                    $gameBadge = $nextTicket['GameIcon'];
                    $consoleName = $nextTicket['ConsoleName'];
                    $reportType = $nextTicket['ReportType'];
                    $reportNotes = str_replace('<br>', "\n", $nextTicket['ReportNotes']);
                    $reportState = $nextTicket['ReportState'];

                    $reportedAt = $nextTicket['ReportedAt'];
                    $niceReportedAt = getNiceDate(strtotime($reportedAt));
                    $reportedBy = $nextTicket['ReportedBy'];
                    $resolvedAt = $nextTicket['ResolvedAt'];
                    $niceResolvedAt = getNiceDate(strtotime($resolvedAt));
                    if ($closedTickets || $resolvedTickets) {
                        $resolvedBy = $nextTicket['ResolvedBy'];
                    }
                    sanitize_outputs(
                        $achTitle,
                        $achDesc,
                        $achAuthor,
                        $gameTitle,
                        $consoleName,
                        $reportNotes,
                        $reportedBy,
                        $resolvedBy
                    );

                    if ($rowCount++ % 2 == 0) {
                        echo "<tr>";
                    } else {
                        echo "<tr>";
                    }

                    echo "<td>";
                    echo "<a href='/ticketmanager.php?i=$ticketID'>$ticketID</a>";
                    echo "</td>";

                    echo "<td>";
                    echo $reportStates[$reportState];
                    echo "</td>";

                    echo "<td style='min-width:25%'>";
                    echo GetAchievementAndTooltipDiv($achID, $achTitle, $achDesc, $achPoints, $gameTitle, $achBadgeName, true);
                    echo "</td>";

                    echo "<td>";
                    echo GetGameAndTooltipDiv($gameID, $gameTitle, $gameBadge, $consoleName);
                    echo "</td>";

                    echo "<td>";
                    echo GetUserAndTooltipDiv($achAuthor, true);
                    echo GetUserAndTooltipDiv($achAuthor, false);
                    echo "</td>";
                    echo "<td>";
                    echo GetUserAndTooltipDiv($reportedBy, true);
                    echo GetUserAndTooltipDiv($reportedBy, false);
                    echo "</td>";
                    if ($closedTickets || $resolvedTickets) {
                        echo "<td>";
                        echo GetUserAndTooltipDiv($resolvedBy, true);
                        echo GetUserAndTooltipDiv($resolvedBy, false);
                        echo "</td>";
                    }

                    echo "<td class='smalldate'>";
                    echo $niceReportedAt;
                    echo "</td>";

                    // echo "<td>";
                    // echo $reportNotes;
                    // echo "</td>";

                    echo "</tr>";
                }

                echo "</tbody></table>";
                echo "</div>";

                echo "<div class='rightalign row'>";
                if ($offset > 0) {
                    $prevOffset = $offset - $maxCount;
                    if ($prevOffset < 0) {
                        $prevOffset = 0;
                    }
                    echo "<a href='$standardFilterUrl" . "$ticketFilters'>First</a> - ";
                    echo "<a href='$standardFilterUrl" . "$ticketFilters&o=$prevOffset'>&lt; Previous $maxCount</a> - ";
                }
                if ($rowCount == $maxCount) {
                    // Max number fetched, i.e. there are more. Can goto next $maxCount.
                    $nextOffset = $offset + $maxCount;
                    echo "<a href='$standardFilterUrl" . "$ticketFilters&o=$nextOffset'>Next $maxCount &gt;</a>";
                    echo " - <a href='$standardFilterUrl" . "$ticketFilters&o=" . ($filteredTicketsCount - ($maxCount - 1)) . "'>Last</a>";
                }
                echo "</div>";
            } else {
                $nextTicket = $ticketData;
                $ticketID = $nextTicket['ID'];
                $achID = $nextTicket['AchievementID'];
                $achTitle = $nextTicket['AchievementTitle'];
                $achDesc = $nextTicket['AchievementDesc'];
                $achAuthor = $nextTicket['AchievementAuthor'];
                $achPoints = $nextTicket['Points'];
                $achBadgeName = $nextTicket['BadgeName'];
                $gameID = $nextTicket['GameID'];
                $gameTitle = $nextTicket['GameTitle'];
                $gameBadge = $nextTicket['GameIcon'];
                $consoleName = $nextTicket['ConsoleName'];
                $reportState = $nextTicket['ReportState'];
                $reportType = $nextTicket['ReportType'];
                $mode = $nextTicket['Hardcore'];
                $reportNotes = str_replace('<br>', "\n", $nextTicket['ReportNotes']);

                $reportedAt = $nextTicket['ReportedAt'];
                $niceReportedAt = getNiceDate(strtotime($reportedAt));
                $reportedBy = $nextTicket['ReportedBy'];
                $resolvedAt = $nextTicket['ResolvedAt'];
                $niceResolvedAt = getNiceDate(strtotime($resolvedAt));
                $resolvedBy = $nextTicket['ResolvedBy'];

                sanitize_outputs(
                    $achTitle,
                    $achDesc,
                    $achAuthor,
                    $gameTitle,
                    $consoleName,
                    $mode,
                    $reportNotes,
                    $reportedBy,
                    $resolvedBy
                );

                echo "<table><tbody>";

                echo "<th>ID</th>";
                echo "<th>State</th>";
                echo "<th>Achievement</th>";
                echo "<th>Game</th>";
                echo "<th>Developer</th>";
                echo "<th>Reporter</th>";
                echo "<th>Resolver</th>";
                echo "<th>Reported At</th>";

                echo "<tr>";

                echo "<td>";
                echo "<a href='/ticketmanager.php?i=$ticketID'>$ticketID</a>";
                echo "</td>";

                echo "<td>";
                echo $reportStates[$reportState];
                echo "</td>";

                echo "<td style='min-width:25%'>";
                echo GetAchievementAndTooltipDiv($achID, $achTitle, $achDesc, $achPoints, $gameTitle, $achBadgeName, true);
                echo "</td>";

                echo "<td style='min-width:25%'>";
                echo GetGameAndTooltipDiv($gameID, $gameTitle, $gameBadge, $consoleName);
                echo "</td>";

                echo "<td>";
                echo GetUserAndTooltipDiv($achAuthor, true);
                echo GetUserAndTooltipDiv($achAuthor, false);
                echo "</td>";

                echo "<td>";
                echo GetUserAndTooltipDiv($reportedBy, true);
                echo GetUserAndTooltipDiv($reportedBy, false);
                echo "</td>";

                echo "<td>";
                echo GetUserAndTooltipDiv($resolvedBy, true);
                echo GetUserAndTooltipDiv($resolvedBy, false);
                echo "</td>";

                echo "<td class='smalldate'>";
                echo $niceReportedAt;
                echo "</td>";

                echo "</tr>";

                echo "<tr>";
                echo "<td>";
                echo "Notes: ";
                echo "</td>";
                echo "<td colspan='7'>";
                echo "<code>$reportNotes</code>";
                echo "</td>";
                echo "</tr>";

                if (isset($mode)) {
                    echo "<tr>";
                    echo "<td>";
                    echo "Mode: ";
                    echo "</td>";
                    echo "<td colspan='7'>";
                    echo "<b>$reportModes[$mode]</b>";
                    echo "</td>";
                    echo "</tr>";
                }

                echo "<tr>";
                echo "<td>";
                echo "Report Type: ";
                echo "</td>";
                echo "<td colspan='7'>";
                echo ($reportType == 1) ? "<b>Triggered at wrong time</b>" : "<b>Doesn't Trigger</b>";
                echo "</td>";
                echo "</tr>";

                echo "<tr>";
                echo "<td></td><td colspan='7'>";
                echo "<div class='temp'>";
                echo "<a href='ticketmanager.php?g=$gameID'>View other tickets for this game</a>";
                echo "</div>";
                echo "</td>";
                echo "</tr>";

                if ($numOpenTickets > 0 || $numClosedTickets > 0) {
                    if ($numOpenTickets > 0) {
                        echo "<tr>";
                        echo "<td></td><td colspan='7'>";
                        echo "Found $numOpenTickets other open tickets for this achievement: ";

                        foreach ($altTicketData as $nextTicket) {
                            $nextTicketID = $nextTicket['ID'];
                            settype($nextTicketID, 'integer');
                            settype($ticketID, 'integer');

                            if ($nextTicketID !== $ticketID && ($nextTicket['ReportState'] == 1)) {
                                echo "<a href='ticketmanager.php?i=$nextTicketID'>$nextTicketID</a>, ";
                            }
                        }

                        echo "</td>";
                        echo "</tr>";
                    }
                    if ($numClosedTickets > 0) {
                        echo "<tr>";
                        echo "<td></td><td colspan='7'>";
                        echo "Found $numClosedTickets closed tickets for this achievement: ";

                        foreach ($altTicketData as $nextTicket) {
                            $nextTicketID = $nextTicket['ID'];
                            settype($nextTicketID, 'integer');
                            settype($ticketID, 'integer');
                            settype($nextTicket['ReportState'], 'integer');

                            if ($nextTicketID !== $ticketID && ($nextTicket['ReportState'] !== 1)) {
                                echo "<a href='ticketmanager.php?i=$nextTicketID'>$nextTicketID</a>, ";
                            }
                        }

                        echo "</td>";
                        echo "</tr>";
                    }
                } else {
                    echo "<tr>";
                    echo "<td></td><td colspan='7'>";
                    echo "<div class='temp'>";
                    echo "No other tickets found for this achievement";
                    echo "</div>";
                    echo "</td>";
                    echo "</tr>";
                }

                echo "<tr>";
                echo "<td></td><td colspan='7'>";
                echo "<div class='temp'>";
                $awardCount = getAwardsSince($achID, $reportedAt);
                echo "This achievement has been earned " . $awardCount['softcoreCount'] . " <b>(" . $awardCount['hardcoreCount'] . ")</b> "
                    . ($awardCount['hardcoreCount'] == 1 ? "time" : "times") . " since this ticket was created.";
                echo "</div>";
                echo "</td>";
                echo "</tr>";

                if ($permissions >= Permissions::Developer) {
                    echo "<tr>";

                    echo "<td>Reporter:</td>";
                    echo "<td colspan='7'>";
                    echo "<div class='smallicon'>";
                    echo "<span>";
                    $msgPayload = "Hi [user=$reportedBy], I'm contacting you about ticket retroachievements.org/ticketmanager.php?i=$ticketID ";
                    $msgPayload = rawurlencode($msgPayload);
                    $msgTitle = rawurlencode("Bug Report ($gameTitle)");
                    echo "<a href='createmessage.php?t=$reportedBy&amp;s=$msgTitle&p=$msgPayload'>Contact the reporter - $reportedBy</a>";
                    echo "</span>";
                    echo "</div>";
                    echo "</td>";
                    echo "</tr>";
                }

                echo "<tr>";
                echo "<td></td><td colspan='7'>";

                $numAchievements = getUserUnlockDates($reportedBy, $gameID, $unlockData);
                $unlockData[] = ['ID' => 0, 'Title' => 'Ticket Created', 'Date' => $reportedAt, 'HardcoreMode' => 0];
                usort($unlockData, fn ($a, $b) => strtotime($b["Date"]) - strtotime($a["Date"]));

                $unlockDate = null;
                foreach ($unlockData as $unlockEntry) {
                    if ($unlockEntry['ID'] == $achID) {
                        $unlockDate = $unlockEntry['Date'];
                        break;
                    }
                }

                if ($unlockDate != null) {
                    echo "$reportedBy earned this achievement at " . getNiceDate(strtotime($unlockDate));
                    if ($unlockDate >= $reportedAt) {
                        echo " (after the report).";
                    } else {
                        echo " (before the report).";
                    }
                } elseif ($numAchievements == 0) {
                    echo "$reportedBy has not earned any achievements for this game.";
                } else {
                    echo "$reportedBy did not earn this achievement.";
                }
                echo "</td></tr>";

                if ($numAchievements > 0 && $permissions >= Permissions::Developer) {
                    echo "<tr><td></td><td colspan='7'>";

                    echo "<div class='devbox'>";
                    echo "<span onclick=\"$('#unlockhistory').toggle(); return false;\">Click to show player unlock history for this game</span><br>";
                    echo "<div id='unlockhistory' style='display: none'>";
                    echo "<table>";

                    foreach ($unlockData as $unlockEntry) {
                        echo "<tr><td>";
                        if ($unlockEntry['ID'] == 0) {
                            echo "Ticket Created - ";
                            echo ($reportType == 1) ? "Triggered at wrong time" : "Doesn't Trigger";
                        } else {
                            echo GetAchievementAndTooltipDiv($unlockEntry['ID'], $unlockEntry['Title'], $unlockEntry['Description'],
                                                            $unlockEntry['Points'], $gameTitle, $unlockEntry['BadgeName'], true);
                        }
                        echo "</td><td>";
                        $unlockDate = getNiceDate(strtotime($unlockEntry['Date']));
                        if ($unlockEntry['ID'] == $achID) {
                            echo "<b>$unlockDate</b>";
                        } else {
                            echo $unlockDate;
                        }
                        echo "</td><td>";
                        if ($unlockEntry['HardcoreMode'] == 1) {
                            if ($unlockEntry['ID'] == $achID) {
                                echo "<b>Hardcore</b>";
                            } else {
                                echo "Hardcore";
                            }
                        }
                        echo "</td></tr>";
                    }

                    echo "</table></div></div>";
                    echo "</td></tr>";
                }

                if ($user == $reportedBy || $permissions >= Permissions::Developer) {
                    echo "<tr>";

                    echo "<td>Action: </td><td colspan='7'>";
                    echo "<div class='smallicon'>";
                    echo "<span>";

                    echo "<b>Please, add some comments about the action you're going to take.</b><br>";
                    echo "<form method=post action='ticketmanager.php?i=$ticketID'>";
                    echo "<input type='hidden' name='i' value='$ticketID'>";

                    echo "<select name='action' required>";
                    echo "<option value='' disabled selected hidden>Choose an action...</option>";

                    if ($reportState == 1) {
                        if ($user == $reportedBy && $permissions < Permissions::Developer) {
                            echo "<option value='closed-mistaken'>Close - Mistaken report</option>";
                        } elseif ($permissions >= Permissions::Developer) {
                            echo "<option value='resolved'>Resolve as fixed (add comments about your fix below)</option>";
                            echo "<option value='demoted'>Demote achievement to Unofficial</option>";
                            echo "<option value='network'>Close - Network problems</option>";
                            echo "<option value='not-enough-info'>Close - Not enough information</option>";
                            echo "<option value='wrong-rom'>Close - Wrong ROM</option>";
                            echo "<option value='unable-to-reproduce'>Close - Unable to reproduce</option>";
                            echo "<option value='closed-mistaken'>Close - Mistaken report</option>";
                            echo "<option value='closed-other'>Close - Another reason (add comments below)</option>";
                        }
                    } else { // ticket is not open
                        echo "<option value='reopen'>Reopen this ticket</option>";
                    }

                    echo "</select>";

                    echo " <input type='submit' value='Perform action'>";
                    echo "</form>";

                    echo "</span>";
                    echo "</div>";
                    echo "</td>";
                    echo "</tr>";
                }
                echo "<tr>";
                echo "<td colspan='5'>";
                echo "<div class='commentscomponent'>";

                echo "<h4>Comments</h4>";
                RenderCommentsComponent($user,
                    $numArticleComments,
                    $commentData,
                    $ticketID,
                    ArticleType::AchievementTicket,
                    $permissions
                );

                echo "</div>";
                echo "</td>";
                echo "</tr>";

                echo "</tbody></table>";
                echo "</div>";

                if ($permissions >= Permissions::Developer && getAchievementMetadata($achID, $dataOut)) {
                    getCodeNotes($gameID, $codeNotes);
                    $achMem = $dataOut['MemAddr'];
                    echo "<div class='devbox'>";
                    echo "<span onclick=\"$('#achievementlogic').toggle(); return false;\">Click to show achievement logic</span><br>";
                    echo "<div id='achievementlogic' style='display: none'>";

                    echo "<div style='clear:both;'></div>";
                    echo "<li> Achievement ID: " . $achID . "</li>";
                    echo "<div>";
                    echo "<li>Mem:</li>";
                    echo "<code>" . htmlspecialchars($achMem) . "</code>";
                    echo "<li>Mem explained:</li>";
                    echo "<code>" . getAchievementPatchReadableHTML($achMem, $codeNotes) . "</code>";
                    echo "</div>";

                    echo "</div>"; // achievementlogic
                    echo "</div>"; // devbox
                }
            }
        }
        echo "</div>";
        ?>
        <br>
    </div>
</div>
<?php RenderFooter(); ?>
</body>
<?php RenderHtmlEnd(); ?>
