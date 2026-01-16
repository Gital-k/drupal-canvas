<?php

namespace CanvasCodingStandard\Sniffs\Namespacing;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;

class LanguageConstantsSniff implements Sniff {

  private const EXCLUDED_CONSTANTS = [
    'NULL', 'TRUE', 'FALSE',
  ];

  public function register() {
    return [T_STRING];
  }

  public function process(File $phpcsFile, $stackPtr) {
    $tokens = $phpcsFile->getTokens();
    $functionName = $tokens[$stackPtr]['content'];

    // Always check for excluded constants.
    if (in_array(strtoupper($functionName), self::EXCLUDED_CONSTANTS, TRUE)) {
      $before = $phpcsFile->findPrevious(T_WHITESPACE, $stackPtr - 1, NULL, TRUE);
      if ($before !== FALSE && $tokens[$before]['code'] === T_NS_SEPARATOR) {
        $phpcsFile->addError(
          "Do not prefix language constant '%s' with a backslash.",
          $stackPtr,
          'BackslashLanguageConstant',
          [$functionName]
        );
      }
    }
  }

}
