<?php
/* Rewrite the semantic comment at the top of the template's style.css file which identifies
 * to WordPress how the template should identify itself.
 */
function fudge_style_css($style_css_path, $site_name, &$theme_props)
{
  $style_css = file_get_contents($style_css_path);
  if (!$style_css)
  {
    echo "error: could not read \"$style_css_path\" or it is empty!\n";
    return false;
  }

  $comment_end_pos = strpos($style_css, '*/');
  if ($comment_end_pos <= 0)
  {
    echo "error: could not identify end of theme preamble in \"$style_css_path\"\n";
    return false;
  }
  $lines = array_filter(
    array_map('trim',
      explode("\n",
        substr($style_css, 0, $comment_end_pos)
      )
    )
  , 'strlen'
  );
  if ($lines[0] != '/*')
  {
    echo "error: could not identify beginning of theme preamble in \"$style_css_path\"\n";
    return false;
  }
  array_shift($lines);

  $props = [];
  foreach ($lines as $line)
  {
    if (strpos($line, ':') === false)
      continue;
    list ($k, $v) = explode(':', $line, 2);
    $props[trim($k)] = trim($v);
  }
/*
  if (count($props) < 5)
  {
    echo "error: theme preamble has wrong numebr of keys\n";
    return false;
  }
*/
  if (!array_key_exists('Description', $props)
  ||  !array_key_exists('Version', $props)
  ||  !array_key_exists('Tags', $props)
  ||  !array_key_exists('Author', $props)
  ) {
    echo "error: theme preamble missing some key(s)\n";
    return false;
  }

  if (!array_key_exists('Theme Name', $props))
  {
    echo "warning: theme name missing. substituting \"Untitled\"\n";
    $props['Theme Name'] = "$site_name";
  }
  else
    $props['Theme Name'] = "$site_name - {$props['Theme Name']}";

  if (strlen($props['Tags']))
    $props['Tags'] .= ', exodous-import';
  else
    $props['Tags'] = 'exodous-import';

  $new_style_css = "/*\n";
  foreach ($props as $k => $v)
  {
    $new_style_css .= "\n$k: $v\n";
  }
  $new_style_css .= "\n*/";
  $new_style_css .= substr($style_css, $comment_end_pos+2);

  $theme_props = $props;
  return $new_style_css;
}

