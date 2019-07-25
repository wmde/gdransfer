#!/usr/bin/php
<?php
function fatal($errmsg, $errcode=0) {
	printf('FATAL: '.$errmsg."\n");
	exit($errcode);
}

function debug($msg) {
	printf('DEBUG: '.$msg."\n");
}

function  usage($errcode=0){
	printf("Usage: gdransfer owner source user
	owner:	email address of user currently owning the source
	source:	the folder to transfer
	user:	email address of the new owner"); exit($errcode);
}

$gen_opts = [ 'name', 'help', 'argnum' ];
$actions = [
	'transfer'	=> [
		'name'		=> 'transfer',
		'help'		=> 'Action "transfer" requires three arguments: the owner of the files, the id of the source, and the target user who will get all files.',
		'argnum'	=> 5
	],
	'getid'		=> [
		'name'		=> 'getid',
		'help'		=> 'Action "getid" requires two arguments: the owner of a file or folder, and its name. It returns its id which you can use with the "transfer" action.',
		'argnum'	=> 4
	],
];

$gam_path = '~/bin/gamadv-xtd3/gam ';

#$gam_redirect = ' 2> /dev/null';
$gam_redirect = '';
$gam = [
	'src'		=> $gam_path.'user %s show fileinfo anydrivefilename "%s" id'.$gam_redirect,
	'all_dirs'	=> $gam_path.'print filelist query "mimeType = \'application/vnd.google-apps.folder\'" parents name id'.$gam_redirect,
	'all_files'	=> $gam_path.'user %s show filelist select "%s" showparent name id'.$gam_redirect,
	'chown'		=> $gam_path.'user %s add drivefileacl id:%s user %s role owner'.$gam_redirect,
	'chowns'		=> $gam_path.'user %s add drivefileacl ids:"%s" user %s role owner'.$gam_redirect,
];

if($argc < 2) fatal(sprintf("Specify an action: %s\n", join(', ', array_keys($actions) ) ),2);
$ACTION = $argv[1];
if(array_key_exists($ACTION, $actions)) {
	$action = $actions[$ACTION];
} else {
	 fatal(sprintf("Specify an action: %s\n", join(', ', array_keys($actions) ) ),2);
}
if($argc < $action['argnum']) fatal(sprintf("Too few arguments: %s.\n\n%s", $argc, $action['help']),2);
if($argc > $action['argnum']) fatal(sprintf("Too many arguments: %s.\n\n%s", $argc, $action['help']),3);

function check_email_address($ema) {
	if(filter_var($ema, FILTER_VALIDATE_EMAIL)) {
		#debug("$ema is a valid email address.");
		return($ema);
	} else {
		throw new Exception("'$ema' is not a valid email address");
	}
}

switch($action['name']) {
	case 'transfer':
		try {
			$action['owner'] = check_email_address($argv[2]);
			$action['user'] = check_email_address($argv[4]);
		} catch (Exception $e) {
			fatal($e, 196);
		}
		$action['source'] = $argv[3];
		$action['drive'] = $action['owner'];
		#$GDRIVE_OWNER = 'all users';

		break;
	case 'getid':
		$action['drive'] = $argv[2];
		$action['filename'] = $argv[3];
		break;
	default:
		fatal('Unknown action.', 240);
}

function getid($a) {
	debug('Entering getid');
	global $gam;
	$drive = $a['drive'];
	$filename = $a['filename'];

	printf("Checking for existence of %s …", $a['filename']);
	#printf($gam['src'], $a['drive'], $a['filename'], "\n");
	$src = shell_exec(sprintf($gam['src'], $a['drive'], $a['filename']));
	$source = explode("\n", $src);

	preg_match_all("/.*(\d+).*/", $source[0], $match);
	#debug('Dumping match …'); var_dump($match);

	printf($src."\nChoose the appropriate file or folder and invoke gdransfer with the transfer action and the correct id.");
	$source = explode("\n", $src);
	#debug('Dumping source …'); var_dump($source);
	exit(0);

}


function transfer($a) {
	debug('Entering transfer');
	global $gam;
	$drive = $a['drive'];
	$source = $a['source'];
	$user = $a['user'];
	#printf($gam['all_files'], $a['drive'], $a['source']);
	$src = shell_exec(sprintf($gam['all_files'], $a['drive'], $a['source']));
	$filenames = explode("\n", $src);
	#debug('Dumping filenames …'); var_dump($filenames);

	$fields = str_getcsv($filenames[0]);
	#debug('Dumping fields …'); var_dump($fields);
	$files = [];
	foreach($filenames as $f) {
		if("" !== $f) $files[] = csv_line2array_element($f, $fields);
	}
	#debug('Dumping files[0] …'); var_dump($files[0]);
	unset($files[0]);
	#debug('Dumping files …'); var_dump($files);
	$ids = [];
	foreach($files as $f) {
		$ids[] = $f['id'];
	}
	#debug('Dumping ids …'); var_dump($ids);
	if(1 == sizeof($ids)) {
		#printf($gam['chown']."\n",$a['drive'],$ids,$a['user']);
		printf("Transferring ownership of 1 file or folder. This should be quick.\n");
		$transfer = shell_exec(sprintf($gam['chown'],$a['drive'],$ids,$a['user']));
		printf($transfer."\n");
	} elseif(1 < sizeof($ids)) {
		$idlist = join(",",$ids);
		printf("Transferring ownership of %d files and/or folders. This may take some time.\n", sizeof($ids));
		#printf($gam['chowns']."\n",$a['drive'],$idlist,$a['user']);
		$transfer = shell_exec(sprintf($gam['chowns'],$a['drive'],$idlist,$a['user']));
		printf($transfer."\n");
	} else {
		printf("Nothing to do.");
	}
	exit(0);
}
$src = $action['name']($action);

function csv_line2array_element( $line, $fields = [ 'Owner','parents','title','id' ] ) {
	#$parent_fields = [ 'id','parentLink','isRoot' ];
	if("" !== $line) $d = str_getcsv($line);
	else return false;
	$date = [];
	foreach($fields as $i => $f) {
		$date[$f] = $d[$i];
	}
	if(isset($date['parents'])) {
		$date['parents'] = [];
		for( $x = 0; $x < sizeof($fields); $x++ ) array_shift($d);
#		debug('Dumping d …'); var_dump($d);
		while(sizeof($d) > 0) {
			$p = [];
			foreach($parent_fields as $i => $f) {
				switch(preg_match('/parents\.(\d)\.(\w*)/',$d[$i], $matches)) {
					case 1:
#						debug('Found match');
						$p[$f] = $matches[2];
						break;
					case false:
#						debug('Error while preg_match\'ing.');
					case 0:
					default:
#						debug('No match.');
						if('' !== $d[$i]) $p[$f] = $d[$i];
						break;
				}
			}
			if(0 !== sizeof($p)) $date['parents'][] = $p;
			for( $x = 0; $x < sizeof($parent_fields); $x++ ) array_shift($d);
		}
	}
	return $date;
}

function arrayze($csv) {
	$a = [];
	foreach($csv as $line) {
		$a[] = csv_line2array_element($line);
	}
	return $a;
}

function is_parent($file, $parent) {
	if(!isset($file['parents'])) return false;
	foreach($file['parents'] as $p) {
		if(trim($parent['id']) == trim($p['id'])) return true;
	}
	return false;
}

function get_descendants($set, $parent, $recursive=TRUE ) {
	$descendants = [];
	foreach($set as $s) {
		if(is_parent($s, $parent)) {
			$descendants[] = $s;
			if($recursive) {
				array_merge($descendants, get_descendants($s, $parent) );
			}
		}
	}
	return $descendants;
}

