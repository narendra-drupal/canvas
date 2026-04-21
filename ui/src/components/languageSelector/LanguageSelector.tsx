import { useState } from 'react';
import { ChevronDownIcon, GlobeIcon } from '@radix-ui/react-icons';
import { Button, DropdownMenu, Flex, Text } from '@radix-ui/themes';

import { useGetLanguagesQuery } from '@/services/languages';

const LanguageSelector = () => {
  const { data: languages = [], isLoading } = useGetLanguagesQuery();
  const [selectedLanguage, setSelectedLanguage] = useState<string>('');

  // Find the default language when data is loaded.
  const defaultLanguage = languages.find((lang) => lang.isDefault);
  const currentLanguage =
    selectedLanguage || defaultLanguage?.id || languages[0]?.id || '';

  const handleLanguageChange = (languageId: string) => {
    setSelectedLanguage(languageId);
    // Implement actual language switching logic.
  };

  const currentLangObj = languages.find((lang) => lang.id === currentLanguage);

  if (isLoading || languages.length === 0) {
    return null;
  }

  // If there's only one language, don't show the selector.
  if (languages.length === 1) {
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
        {/* Optional: Add a divider and "Localization Setting" menu item */}
        <DropdownMenu.Separator />
        <DropdownMenu.Item>
          <Text>Localization Setting</Text>
        </DropdownMenu.Item>
      </DropdownMenu.Content>
    </DropdownMenu.Root>
  );
};

export default LanguageSelector;
