import { Box, Flex, Text } from '@radix-ui/themes';

import { buildFontFaceSnippet } from '@/features/brandKit/fontCss';
import { getFontPreviewStyle } from '@/features/brandKit/variableFontState';
import { translate } from '@/utils/translate';

import type { AssetLibraryFont } from '@/types/CodeComponent';

import styles from '../BrandKitPanel.module.css';

// Font preview pangrams - these are intentionally not translated as they are
// used to display all letters of the font for preview purposes.
const FONT_PREVIEW_PANGRAM = 'The quick brown fox jumps over the lazy dog.';
const FONT_PREVIEW_ALPHANUMERIC = 'Aa Bb Cc 1234567890';

type FontPreviewCardProps = {
  font: AssetLibraryFont;
};

const FontPreviewCard = ({ font }: FontPreviewCardProps) => (
  <Box className={styles.previewSection}>
    <style>{buildFontFaceSnippet(font)}</style>
    <Flex direction="column" gap="2">
      <Text size="1" color="gray">
        {translate('Preview')}
      </Text>
      <Text
        size="6"
        className={styles.previewSample}
        style={getFontPreviewStyle(font)}
      >
        {FONT_PREVIEW_PANGRAM}
      </Text>
      <Text
        size="3"
        color="gray"
        className={styles.previewSecondary}
        style={getFontPreviewStyle(font)}
      >
        {FONT_PREVIEW_ALPHANUMERIC}
      </Text>
    </Flex>
  </Box>
);

export default FontPreviewCard;
