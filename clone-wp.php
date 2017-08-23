#!/usr/bin/php
<?php

define('WP_CONTENT_PATH', realpath('/var/www/wp-content'));
define('WP_THEME_PATH', realpath(WP_CONTENT_PATH.'/themes'));
define('WP_UPLOADS_PATH', realpath(WP_CONTENT_PATH.'/uploads/sites'));

require_once 'style.css.php';

function usage($status=1) {
  global $argv;
  echo <<<USAGE
usage: {$argv[0]} <existing-domain> <new-domain>

USAGE;
  exit($status);
}

if (count($argv) != 3)
  usage();

require_once 'db-settings.php';
require_once 'copy.php';

$src_domain = $argv[1];
$dst_domain = $argv[2];

echo "source:      $src_domain\n";
echo "destination: $dst_domain\n";

function query($sql)
{
  global $db;
  $result = $db->query($sql);
  if (!$result)
  {
    echo "error: database query error\n";
    echo "mysqld sez: {$db->error}\n";
    echo "sql: $sql\n";
    throw new Exception('database error');
  }
  return $result;
}

function queryOne($sql)
{
  $result = query($sql);
  $rval = $result->fetch_assoc();
  $result->free();
  return $rval;
}

$stdin = fopen('php://stdin', 'r');
function prompt($prompt)
{
  global $stdin;
  while (1)
  {
    echo $prompt;
    $answer = rtrim(fgets($stdin));
    if ($answer == 'y' || $answer == 'n')
      return $answer == 'y';
    echo "invalid input.\n";
  }
}

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, 'wordpress');
$src_domain_esc = $db->real_escape_string($src_domain);
$dst_domain_esc = $db->real_escape_string($dst_domain);

echo "identifying source blog ID...\n";
$src_wp_blog = queryOne("select * from wp_blogs where domain = '$src_domain_esc'");
if (!$src_wp_blog) {
  echo "error: could not identify source blog $src_domain\n";
  exit(1);
}
$src_blog_id = $src_wp_blog['blog_id'];
echo "source blog id: $src_blog_id\n";
$src_table_prefix = "wp_{$src_blog_id}_";
$src_options_table_name = $src_table_prefix."options";
$src_options_table_name_esc = $db->real_escape_string($src_options_table_name);

echo "checking to see if destination blog already exists...\n";
$dst_wp_blog = queryOne("select * from wp_blogs where domain = '$dst_domain_esc'");
if ($dst_wp_blog) {
  echo "error: destination blog already exists with blog id {$dst_wp_blog['blog_id']}\n";
  exit(1);
}

echo "identifying source template...\n";
$src_template_name = queryOne("select option_value template_name from `$src_options_table_name_esc` where option_name='template'")['template_name'];
$src_template_path = realpath(WP_THEME_PATH.'/'.$src_template_name);
if ($src_template_path == WP_THEME_PATH) {
  echo "error: source template '$src_template_name' equates with WP_THEME_PATH '".WP_THEME_PATH."'\n";
  exit(1);
}
if (!file_exists($src_template_path)) {
  echo "error: source template $src_template_path doesn't exist\n";
  exit(1);
}
echo "found source template $src_template_path\n";
if (!file_exists("$src_template_path/style.css")) {
  echo "error: found source uploads dir at $src_uploads_path but could not find style.css therein\n";
  exit(1);
}
echo "found source template style.css at $src_template_path/style.css\n";

echo "identifying destination template...\n";
$dst_template_name = $dst_domain;
$dst_template_path = WP_THEME_PATH.'/'.$dst_template_name;
if (file_exists($dst_template_path)) {
  echo "error: destination template exists at $dst_template_path\n";
  exit(1);
}
echo "destination template is $dst_template_path\n";

echo "determining destination blog_id...\n";
$dst_blog_id = queryOne("select max(blog_id)+1 dst_blog_id from wp_blogs")['dst_blog_id'];
echo "destination blog id: $dst_blog_id\n";
$dst_table_prefix = "wp_{$dst_blog_id}_";
$dst_options_table_name = $dst_table_prefix."options";
$dst_options_table_name_esc = $db->real_escape_string($dst_options_table_name);

echo "identifying source uploads...\n";
$src_uploads_path = WP_UPLOADS_PATH.'/'.$src_blog_id;
if (!file_exists($src_uploads_path)) {
  echo "error: could not find source uploads dir at $src_uploads_path\n";
  exit(1);
}
echo "source uploads dir found at $src_uploads_path\n";

echo "identifying destination uploads...\n";
$dst_uploads_path = WP_UPLOADS_PATH.'/'.$dst_blog_id;
if (file_exists($dst_uploads_path)) {
  echo "error: destination uploads dir already exists at $dst_uploads_path\n";
  exit(1);
}
echo "destination uploads dir found at $dst_uploads_path\n";

echo "identifying tables belonging to source blog...\n";
$src_tables_query = query("show tables like 'wp_{$src_blog_id}_%'");
$table_map = array();
while ($src_table_row = $src_tables_query->fetch_row()) {
  $src_table = $src_table_row[0];
  if (substr($src_table, 0, strlen($src_table_prefix)) != $src_table_prefix) {
    echo "error: could not calculate new table name for `$src_table`\n";
    exit(1);
  }
  $dst_table = $dst_table_prefix . substr($src_table, strlen($src_table_prefix));
  $table_map[$src_table] = $dst_table;
  echo "found source table $src_table; will copy to $dst_table\n";
}
$src_tables_query->free();

echo "checking that destination tables do not exist...\n";
foreach ($table_map as $src_table => $dst_table) {
  $dst_table_esc = $db->real_escape_string($dst_table);
  $sql = "show tables like '$dst_table_esc'";
  $tables_like = queryOne($sql);
  if ($tables_like) {
    echo "error: destination table $dst_table already exists\n";
    exit(1);
  }
  echo "$sql -- returned no results\n";
}

echo "obtaining @@sql_mode...\n";
$sql_mode = queryOne('select @@sql_mode')['@@sql_mode'];
$sql_mode = array_flip(explode(',', $sql_mode));
unset($sql_mode['NO_ZERO_DATE']);
$sql_mode = implode(',',array_keys($sql_mode));

echo "\nall conditions green. here's the SQL I want to execute:\n\n";
$clone_sql = array();

$sql_mode_esc = $db->real_escape_string($sql_mode);
$clone_sql[] = "set @@sql_mode='$sql_mode_esc'";
foreach ($table_map as $src_table => $dst_table) {
  $src_table_esc = $db->real_escape_string($src_table);
  $dst_table_esc = $db->real_escape_string($dst_table);
  $clone_sql[] = "drop table if exists `$dst_table_esc`";
  $clone_sql[] = "create table `$dst_table_esc` like `$src_table_esc`";
  $clone_sql[] = "insert into `$dst_table_esc` select * from `$src_table_esc`";
}

$dst_url = "http://$dst_domain";
$clone_sql[] = "update `$dst_options_table_name_esc` set option_value='$dst_url' where option_name in ('siteurl', 'home')";
$clone_sql[] = "update `$dst_options_table_name_esc` set option_value='$dst_template_name' where option_name = 'template'";
$clone_sql[] = "update `$dst_options_table_name_esc` set option_value='$dst_template_name' where option_name = 'stylesheet'";
$clone_sql[] = "update `$dst_options_table_name_esc` set option_name='{$dst_table_prefix}roles'      where option_name='{$src_table_prefix}roles';";
$clone_sql[] = "update `$dst_options_table_name_esc` set option_name='{$dst_table_prefix}user_roles' where option_name='{$src_table_prefix}user_roles';";

/* Write new "blog" */
$clone_sql[] = <<<SQL
  insert into wp_blogs (
    blog_id
  , site_id
  , domain
  , path
  , registered
  , last_updated
  ) values (
    $dst_blog_id
  , 1
  , '$dst_domain_esc'
  , '/'
  , now()
  , now()
  );
SQL;

foreach ($clone_sql as $sql) {
  echo "  $sql\n";
}

echo "\n\nI'm ready to execute the above SQL, hardlink the uploads files, copy the theme, and edit the theme style.css preamble\n";

if (!prompt("\nclone? y/n ")) {
  echo "not cloning.\nbye.\n";
  exit(0);
}

echo "cloning!\n";

foreach ($clone_sql as $sql) {
  echo "executing sql> $sql\n";
  $result = query($sql);
  if ($result !== TRUE)
    $result->free();
}

echo "hardlinking files from $src_uploads_path to $dst_uploads_path...\n";
recurse_copy($src_uploads_path, $dst_uploads_path, true);

echo "copying $src_template_path to $dst_template_path...\n";
recurse_copy($src_template_path, $dst_template_path);

$theme_props = [];
$dst_style_css_path = "$dst_template_path/style.css";
echo "updating destination theme info in $dst_style_css_path\n";
fudge_style_css($dst_style_css_path, $dst_domain, $theme_props);

echo "clone complete.\n";
