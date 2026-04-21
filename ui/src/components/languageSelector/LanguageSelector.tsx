import { useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { ChevronDownIcon, GlobeIcon } from '@radix-ui/react-icons';
import { Button, DropdownMenu, Flex, Text } from '@radix-ui/themes';

import { useAppDispatch } from '@/app/hooks';
import { setConfiguration } from '@/features/configuration/configurationSlice';
import { componentAndLayoutApi } from '@/services/componentAndLayout';
import { useGetLanguagesQuery } from '@/services/languages';

const LanguageSelector = () => {
  const { data: languages = [], isLoading } = useGetLanguagesQuery();
  const [selectedLanguage, setSelectedLanguage] = useState<string>('');
  const navigate = useNavigate();
  const { entityType, entityId } = useParams();
  const dispatch = useAppDispatch();

  // Find the default language when data is loaded.
  const defaultLanguage = languages.find((lang) => lang.isDefault);
  const currentLanguage =
    selectedLanguage || defaultLanguage?.id || languages[0]?.id || '';

  const handleLanguageChange = (languageId: string) => {
    setSelectedLanguage(languageId);
    const selectedLang = languages.find((lang) => lang.id === languageId);

    if (!selectedLang || !entityType || !entityId) {
      return;
    }

    // If selecting the default language, navigate back to editor.
    if (selectedLang.isDefault) {
      dispatch(
        setConfiguration({
          baseUrl: '/',
          entityType,
          entity: entityId,
          isNew: false,
          isPublished: false,
          devMode: false,
        }),
      );
      // Invalidate cache to force refetch with default language.
      dispatch(
        componentAndLayoutApi.util.invalidateTags([
          { type: 'Layout', id: `${entityType}-${entityId}` },
        ]),
      );
      navigate(`/editor/${entityType}/${entityId}`);
    } else {
      // For non-default languages, navigate to preview with the language URL in state.
      const internalPath = `/node/${entityId}`;
      const languageUrl = `${window.location.origin}/${languageId}${internalPath}`;

      // Navigate to preview and pass the language URL in state.
      navigate(`/preview/${entityType}/${entityId}/full`, {
        state: { languagePreviewUrl: languageUrl, language: languageId },
      });
    }
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
