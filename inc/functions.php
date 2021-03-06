<?php

/* utility function to display database tables */
function print_table($sql) {
	global $db;
	$sth = $db->prepare($sql);
	$sth->execute();
	$array = $sth->fetchAll();
	echo '<table>';
	echo '<thead>';
	echo '<tr>';
	foreach (array_keys($array[0]) as $th)
		echo '<th>'. $th . '</td>';
	echo '</tr>';
	echo '</thead>';
	foreach ($array as $arr)
	{
		echo '<tr>';
		foreach ($arr as $property)
			echo '<td>' . $property . '</td>';
		echo '</tr>';
	}
	echo '</table>';
}
/* print standings */
function print_result_table($tournament_id) {
	global $db;
	$season = get_latest_season_id($tournament_id);
	/* teams quantity in the given $season and $tournament_id */
	$sth = $db->prepare("
			select
			count(tms.team_name_short) as teams_quantity,
			trn.tournament_rounds as rounds,
			trn.tournament_id as tournament_id,
			s.season_id as season_id
			from teams as tms
			inner join tournaments as trn on trn.tournament_id = tms.tournament_id
			inner join seasons as s on tms.season_id = s.season_id
			where trn.tournament_id = ? and s.season_id = ?
		");
	$sth->execute( array($tournament_id, $season) );
	$tournament = $sth->fetch();
	/* teams names, points, games played and games left ordered by points scored */
	$sql = "with points_table as
			(
				select
				m.team_id1 as id,
				ht.team_name_short as name,
				sum(m.points_1) as points,
				count(*) as games_played
				from teams as ht
				inner join matches as m on ht.team_id = m.team_id1
				where m.tournament_id = :tournament_id and m.season_id = :season_id
				group by name
				union all
				select
				m.team_id2 as id,
				at.team_name_short as name,
				sum(m.points_2) as points,
				count(*) as games_played
				from teams as at
				inner join matches as m on at.team_id = m.team_id2
				where m.tournament_id = :tournament_id and m.season_id = :season_id
				group by name
			)
			select id, name, sum(points) as points, sum(games_played) as games_played, ((:qty - 1) * :r - sum(games_played)) as games_left
			from points_table
			group by name
			order by points desc";
	$sth = $db->prepare($sql);
	$sth->bindValue(':tournament_id', $tournament['tournament_id'], PDO::PARAM_INT);
	$sth->bindValue(':season_id', $tournament['season_id'], PDO::PARAM_INT);
	$sth->bindValue(':qty', $tournament['teams_quantity'], PDO::PARAM_INT);
	$sth->bindValue(':r', $tournament['rounds'], PDO::PARAM_INT);
	$sth->execute();
	$standings = $sth->fetchAll();
	/* Get tournament_type */
	$result = $db->query("select tournament_type from tournaments where tournament_id = $tournament_id");
	$tournament_type = $result->fetch()["tournament_type"];
	/* Calculating goals scored and goals conceded for standings */
	foreach ($standings as &$standing) {
		$goals_scored = $goals_conceded = 0;
		$sql = "select * from matches where team_id1 = :id";
		$sth = $db->prepare($sql);
		$sth->bindValue(":id", $standing["id"], PDO::PARAM_INT);
		$sth->execute();
		while( $match = $sth->fetch() ) {
			if ($match["sets_won1"] == "т" && $match["sets_won2"] == "т")
				continue ;
			$goals_scored += $match["sets_won1"] == "т" ? ($tournament_type == 1 ? 10 : 2) : $match["sets_won1"];
			$goals_conceded += $match["sets_won2"] == "т" ? ($tournament_type == 1 ? 10 : 2) : $match["sets_won2"];
		}
		$sql = "select * from matches where team_id2 = :id";
		$sth = $db->prepare($sql);
		$sth->bindValue(":id", $standing["id"], PDO::PARAM_INT);
		$sth->execute();
		while( $match = $sth->fetch() ) {
			if ($match["sets_won1"] == "т" && $match["sets_won2"] == "т")
				continue ;
			$goals_scored += $match["sets_won2"] == "т" ? ($tournament_type == 1 ? 10 : 2) : $match["sets_won2"];
			$goals_conceded += $match["sets_won1"] == "т" ? ($tournament_type == 1 ? 10 : 2) : $match["sets_won1"];
		}
		$standing["goal_diff"] = $goals_scored - $goals_conceded;
	}
	/* Resort standings by points and then by goal_diff */
	array_multisort(array_column($standings, "points"), SORT_DESC, SORT_NUMERIC, array_column($standings, "goal_diff"), SORT_DESC, $standings);
	/* Get match results */
	$sth = $db->prepare("
			select
			m.match_id as match_id,
			m.season_id as season_id,
			m.tournament_id as tournament_id,
			ht.team_name_short as home_team,
			at.team_name_short as away_team,
			m.sets_won1 as home_score,
			m.sets_won2 as away_score
			from matches as m
			inner join teams as ht on ht.team_id = m.team_id1
			inner join teams as at on at.team_id = m.team_id2
			where m.season_id = :season_id and m.tournament_id = :tournament_id
		");
	$sth->bindValue(':tournament_id', $tournament['tournament_id'], PDO::PARAM_INT);
	$sth->bindValue(':season_id', $tournament['season_id'], PDO::PARAM_INT);
	$sth->execute();
	$results = $sth->fetchAll();
	$cols = $tournament['teams_quantity'] + 5;
	if (count($standings) < $tournament['teams_quantity'])
	{
		$sth = $db->prepare("select team_name_short as name from teams where tournament_id = :tournament_id and season_id = :season_id order by name");
		$sth->bindValue(':tournament_id', $tournament['tournament_id'], PDO::PARAM_INT);
		$sth->bindValue(':season_id', $tournament['season_id'], PDO::PARAM_INT);
		$sth->execute();
		$teams = $sth->fetchAll();
		foreach ($teams as $team)
		{
			$double = 0;
			foreach ($standings as $stndn)
				if ($team['name'] == $stndn['name']) {
					$double = 1;
					break ;
				}
			if (!$double)
			{
				$team['points'] = '0';
				$team['goal_diff'] = '0';
				$team['games_played'] = '0';
				$team['games_left'] = ''.($tournament['teams_quantity'] - 1) * $tournament['rounds'];
				array_push($standings, $team);
			}
		}
	}
	echo '<table class="table table-striped table-hover text-center table-sm">';
	echo '<thead class="thead-dark">';
	echo '<tr>';
	echo '<th>Команда</th>';
	for ($i = 0; $i < $tournament['teams_quantity']; $i++)
		echo '<th сolspan="2">' . $standings[$i]['name'] . '</th>';
	echo '<th>Очки</th>';
	echo '<th>+/-</th>';
	echo '<th>Игры</th>';
	echo '<th>Осталось</th>';
	echo '</tr>';
	echo '</thead>';
	echo '<tbody>';
	for ($i = 0; $i < $tournament['teams_quantity']; $i++) {
		echo '<tr>';
		for ($j = 0; $j < $cols; $j++)
		{
			$home_team = $standings[$i]['name'];
			switch ($j)
			{
				case 0:
					echo '<td>' . $home_team . '</td>';
					break ;
				case ($i + 1):
					echo '<td class="bg-dark"></td>';
					break ;
				case ($cols - 4):
					echo '<td>' . $standings[$i]['points'] . '</td>';
					break ;
				case ($cols - 3):
					echo '<td>' . $standings[$i]['goal_diff'] . '</td>';
					break ;
				case ($cols - 2):
					echo '<td>' . $standings[$i]['games_played'] . '</td>';
					break ;
				case ($cols - 1):
					echo '<td>' . $standings[$i]['games_left'] . '</td>';
					break ;
				default:
					$away_team = $standings[$j - 1]['name'];
					echo '<td>' . find_match_results($home_team, $away_team, $results, $tournament) . '</td>';
					break ;
			}
		}
		echo '</tr>';
	}
	echo '</tbody>';
	echo '</table>';
}
/* find match results in 2-dimensional array */
function find_match_results($home_team, $away_team, $results, $tournament) {
	global $subfolder;
	$res = " ";
	foreach ($results as $result)
		if ($result['home_team'] == $home_team && $result['away_team'] == $away_team)
		{
			if ($result['away_score'] != "т" && $result['home_score'] != "т")
				$res .= '<a href="' .$subfolder. '/match.php?id=' . $result['match_id'] . '">';
			$res .= $result['home_score'] . ':' . $result['away_score'];
			if ($result['away_score'] != "т" && $result['home_score'] != "т")
				$res .= '</a>';
			$res .= " ";
		}
		else if ($result['away_team'] == $home_team && $result['home_team'] == $away_team)
		{
			if ($result['away_score'] != "т" && $result['home_score'] != "т")
				$res .= '<a href="' .$subfolder. '/match.php?id=' . $result['match_id'] . '">';
			$res .= $result['away_score'] . ':' . $result['home_score'];
			if ($result['away_score'] != "т" && $result['home_score'] != "т")
				$res .= '</a>';
			$res .= " ";
		}
	if (!(strlen($res) - 1))
	{
		$i = $tournament['rounds'];
		while ($i--)
			$res .= " - ";
	}
	return ($res);
}
/* find player by id */
function find_player_name_by_id($player_id, $players_query) {
	foreach ($players_query as $player)
		if ($player['id'] == $player_id)
			return ($player['second_name'] . " " . $player['first_name']);
	return ("Т");
}

function print_ratings($tournament_id, $type, $season, $tournament_id1 = NULL) {
	global $db;

	$sql = "with player as (
			select 
			player_id11 as id,
			count(*) as played,
			sum(score1) as scored,
			sum(score2) as conceded
			from games
			inner join matches on games.match_id = matches.match_id
			where " . ($tournament_id1 ? "(tournament_id = :t or tournament_id = :t1)" : "tournament_id = :t") . " and season_id = :s
			group by id
			union all
			select
			player_id12 as id,
			count(*) as played,
			sum(score1) as scored,
			sum(score2) as conceded
			from games
			inner join matches on games.match_id = matches.match_id
			where player_id12 is not null and " . ($tournament_id1 ? "(tournament_id = :t or tournament_id = :t1)" : "tournament_id = :t") . " and season_id = :s
			group by id
			union all
			select player_id21 as id,
			count(*) as played,
			sum(score2) as scored,
			sum(score1) as conceded
			from games
			inner join matches on games.match_id = matches.match_id
			where " . ($tournament_id1 ? "(tournament_id = :t or tournament_id = :t1)" : "tournament_id = :t") . " and season_id = :s
			group by id
			union all
			select
			player_id22 as id,
			count(*) as played,
			sum(score2) as scored,
			sum(score1) as conceded
			from games
			inner join matches on games.match_id = matches.match_id
			where id is not null and " . ($tournament_id1 ? "(tournament_id = :t or tournament_id = :t1)" : "tournament_id = :t") . " and season_id = :s
			group by id
		),
		participation as (
			with lineups as (
				select * from rosters
				inner join games on rosters.id = games.player_id11
				union all
				select * from rosters
				inner join games on rosters.id = games.player_id12
				union all
				select * from rosters
				inner join games on rosters.id = games.player_id21
				union all
				select * from rosters
				inner join games on rosters.id = games.player_id22
			),
			team_matches as (
				with tm as (
					select team_id1 as team_id, count(*) as count_matches from matches
					where " . ($tournament_id1 ? "(tournament_id = :t or tournament_id = :t1)" : "tournament_id = :t") . " and season_id = :s and (sets_won1 != 'т' and sets_won2 != 'т')
					group by team_id1
					union all
					select team_id2 as team_id, count(*) as count_matches from matches
					where " . ($tournament_id1 ? "(tournament_id = :t or tournament_id = :t1)" : "tournament_id = :t") . " and season_id = :s and (sets_won1 != 'т' and sets_won2 != 'т')
					group by team_id2
				)
				select team_id, sum(count_matches) as team_matches from tm group by team_id
			)
			select id, team_matches.team_id, team_matches from lineups
			inner join team_matches on lineups.team_id = team_matches.team_id
			where " . ($tournament_id1 ? "(tournament_id = :t or tournament_id = :t1)" : "tournament_id = :t") . " and season_id = :s
			group by id
		)
		select player.id, rosters.tournament_id as tournament, sum(player.played) as played, sum(player.scored) as scored, sum(player.conceded) as conceded,
			sum(player.scored) - sum(player.conceded) as diff, first_name as first_name, second_name as name, rosters.rating as rating, team_name_short as team, teams.team_id as team_id, team_matches from player
		inner join rosters on rosters.id = player.id
		inner join players on players.player_id = rosters.player_id
		inner join teams on rosters.team_id = teams.team_id
		inner join participation on participation.id = player.id
		where " . ($tournament_id1 ? "(rosters.tournament_id = :t or rosters.tournament_id = :t1)" : "rosters.tournament_id = :t") . " and rosters.season_id = :s
		group by player.id
		order by rating desc, diff desc, played desc;
	";
	$sth = $db->prepare($sql);
	$sth->bindValue(":t", $tournament_id, PDO::PARAM_INT);
	if ($tournament_id1)
		$sth->bindValue(":t1", $tournament_id1, PDO::PARAM_INT);
	$sth->bindValue(":s", $season, PDO::PARAM_INT);
	$sth->execute();
	$players = $sth->fetchAll();
	/* Count participation in current team's matches */
	foreach ($players as &$player) {
		$sql = "with participation as (
				select * from games
				inner join matches on games.match_id = matches.match_id
				where (player_id11 = :id or player_id12 = :id) and team_id1 = :tid
				union all
				select * from games
				inner join matches on games.match_id = matches.match_id
				where (player_id21 = :id or player_id22 = :id) and team_id2 = :tid
			)
			select count(distinct(match_id)) as participated from participation
		";
		$sth = $db->prepare($sql);
		$sth->bindValue(":id", $player["id"], PDO::PARAM_INT);
		$sth->bindValue(":tid", $player["team_id"], PDO::PARAM_INT);
		$sth->execute();
		$result = $sth->fetch();
		$player["participated"] = $result["participated"];
	}
	$i = 1;
	echo "<table class='table table-sm table-striped table-hover table-ratings'>";
	echo "<thead class='thead-dark'>";
	echo "<tr>";
	echo "<th>№</th>";
	echo "<th>Игрок</th>";
	echo "<th>Команда</th>";
	echo "<th>Партий</th>";
	if ($type == 2) {
		echo "<th>Побед</th>";
		echo "<th>Поражений</th>";
		echo "<th>Ничьих</th>";
	}
	echo "<th>Забито</th>";
	echo "<th>Пропущено</th>";
	echo "<th>Разница</th>";
	echo "<th>Участие в играх</th>";
	echo "<th>Рейтинг</th>";
	echo "<th>Процент участия</th>";
	echo "</tr>";
	echo "</thead>";
	echo "<tbody>";
	foreach ($players as $player0) {
		$participationPercent = round($player0['participated'] / $player0['team_matches'] * 100);
		foreach ($players as $player1) {
			if (strpos($player0['name'], " ") || $player1['id'] == $player0['id'])
				continue ;
			if ($player1['name'] == $player0['name']) {
				if (substr($player0['first_name'], 0, 2) != substr($player1['first_name'], 0, 2)) {
					$player0['name'] .= " " . substr($player0['first_name'], 0, 2) . ".";
					$player1['name'] .= " " . substr($player1['first_name'], 0, 2) . ".";
				} else {
					$player0['name'] .= " " . $player0['first_name'];
					$player1['name'] .= " " . $player1['first_name'];
				}
			}
		}
		echo "<tr". (($participationPercent < 60) ? " class='transparent'" : "") .">";
		echo "<td>" . $i++ ."</td>";
		echo "<td class='text-left'>" . $player0['name'] . "</td>";
		echo "<td>" . $player0['team'] . "</td>";
		echo "<td>" . ($type == 2 ? $player0['played'] / 2 : $player0['played']). "</td>";
		if ($type == 2) {
			/* Get statistics won/forfeit/draw */
			$sth = $db->prepare("
				select game_id, match_id, score1 as score from games where :id in (player_id11, player_id12)
				union all
				select game_id, match_id, score2 as score from games where :id in (player_id21, player_id22)
			");
			$sth->bindValue(":id", $player0["id"], PDO::PARAM_INT);
			$sth->execute();
			$stats = $sth->fetchAll();
			$won = 0;
			$forfeit = 0;
			$draw = 0;
			for ($s = 0, $size = count($stats); $s < $size; $s += 2) {
				$game = $stats[$s];
				$nextGame = $stats[$s + 1];
				if ($game["score"] == "5" && $nextGame["score"] == "5")
						$won++;
				else if ($game["score"] == "5" || $nextGame["score"] == "5")
					$draw++;
				else
					$forfeit++;
			}
			echo "<td>" . $won ."</td>";
			echo "<td>" . $forfeit . "</td>";
			echo "<td>" . $draw . "</td>";
		}
		echo "<td>" . $player0['scored'] . "</td>";
		echo "<td>" . $player0['conceded'] . "</td>";
		echo "<td>" . $player0['diff'] . "</td>";
		echo "<td>" . $player0['participated'] . "</td>";
		echo "<td>" . $player0['rating'] . "</td>";
		echo "<td>" . $participationPercent . "%</td>";
		echo "</tr>";
	}
	echo "</tbody>";
	echo "</table>";
}

function get_latest_season_id($tournament_id) {
	global $db;
	
	$sth = $db->prepare("select max(season_id) as season from teams where tournament_id = :tournament_id");
	$sth->bindValue(':tournament_id', $tournament_id, PDO::PARAM_INT);
	$sth->execute();
	return ($sth->fetch()["season"]);
}