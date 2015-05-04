<?php

/**
 * @file
 * Includes extra settings for your settings.php for included modules.
 *
 * @license GPL v2 http://www.fsf.org/licensing/licenses/gpl.html
 * @author Chris Skene chris at xtfer dot com
 * @copyright Copyright(c) 2015 Christopher Skene
 */

// Whether the Environment Indicator should use the settings.php variables
// for the indicator. On your production environment, you should probably
// set this to FALSE.
$conf['environment_indicator_overwrite'] = TRUE;

// The text that will be displayed on the indicator.
$conf['environment_indicator_overwritten_name'] = 'Development';

// Valid css colors for the text and background of the admin toolbar
// and environment indicator.
$conf['environment_indicator_overwritten_color'] = '#ff5555';
$conf['environment_indicator_overwritten_text_color'] = '#ffffff';

// Where your indicator may appear. Allowed values are "top" and "bottom".
$conf['environment_indicator_overwritten_position'] = 'top';

// A boolean value indicating whether the Environment Indicator should be
// visible at all times, fixed at the top/bottom of the screen.
$conf['environment_indicator_overwritten_fixed'] = FALSE;
