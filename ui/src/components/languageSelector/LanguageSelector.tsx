import { useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { ChevronDownIcon, GlobeIcon } from '@radix-ui/react-icons';
import { Button, DropdownMenu, Flex, Text } from '@radix-ui/themes';

import { useGetLanguagesQuery } from '@/services/languages';

const LanguageSelector = () => {
  const { data: languages = [], isLoading } = useGetLanguagesQuery();
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const { entityType, entityId, width } = useParams();

  // Derive the active language directly from the URL so the dropdown always
  // reflects the correct language on any navigation, including browser
  // back/forward, without needing local state or cleanup logic.
  const activeLanguageId = searchParams.get('language') ?? '';
  const defaultLanguage = languages.find((lang) => lang.isDefault);
  const currentLanguage =
    activeLanguageId || defaultLanguage?.id || languages[0]?.id || '';

  const handleLanguageChange = (languageId: string) => {
    const selectedLang = languages.find((lang) => lang.id === languageId);

    if (!selectedLang || !entityType || !entityId) {
      return;
    }

    // If selecting the default language, navigate back to editor.
    // PagePreview's cleanup effect handles resetting baseUrl and clearing
    // stale language content on unmount.
    if (selectedLang.isDefault) {
      navigate(`/editor/${entityType}/${entityId}`);
    } else {
      // Navigate to preview with the language info in URL query parameter and
      // state. PagePreview's effect handles setting baseUrl and fetching.
      // Preserve the current viewport width, defaulting to 'full' if not set.
      const currentWidth = width || 'full';
      navigate(
        `/preview/${entityType}/${entityId}/${currentWidth}?language=${languageId}`,
        {
          state: { isLanguagePreview: true, language: languageId },
        },
      );
    }
  };

  const currentLangObj = languages.find((lang) => lang.id === currentLanguage);

  if (isLoading || languages.length <= 1) {
    return null;
  }

  return (
    <DropdownMenu.Root>
      <DropdownMenu.Trigger>
        <Button size="2" color="gray" variant="soft">
          <GlobeIcon />
          <Text>{currentLangObj?.name || 'Select Language'}</Text>
          <ChevronDownIcon width="16" height="16" />
        </Button>
      </DropdownMenu.Trigger>
      <DropdownMenu.Content>
        {languages.map((language) => (
          <DropdownMenu.Item
            key={language.id}
            onSelect={() => handleLanguageChange(language.id)}
          >
            <Flex justify="between" width="100%">
              <Text>
                {language.name}
                {language.isDefault && ' (Default)'}
              </Text>
            </Flex>
          </DropdownMenu.Item>
        ))}
      </DropdownMenu.Content>
    </DropdownMenu.Root>
  );
};

export default LanguageSelector;
