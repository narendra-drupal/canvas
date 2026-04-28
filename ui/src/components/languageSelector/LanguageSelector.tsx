import { useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { ChevronDownIcon, GlobeIcon } from '@radix-ui/react-icons';
import { Button, DropdownMenu, Flex, Text } from '@radix-ui/themes';

import { useAppDispatch } from '@/app/hooks';
import { setConfiguration } from '@/features/configuration/configurationSlice';
import {
  initialState as layoutInitialState,
  setInitialLayoutModel,
} from '@/features/layout/layoutModelSlice';
import { setInitialPageData } from '@/features/pageData/pageDataSlice';
import { setHtml } from '@/features/pagePreview/previewSlice';
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
      // Clear the preview HTML to prevent showing stale language content.
      dispatch(setHtml(''));

      // Reset layout model and page data to clear any language-specific content
      dispatch(
        setInitialLayoutModel({
          layout: layoutInitialState.layout,
          model: layoutInitialState.model,
          updatePreview: false,
        }),
      );
      dispatch(setInitialPageData({}));

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

      // Use setTimeout to ensure baseUrl is propagated before invalidation and navigation.
      setTimeout(() => {
        // Invalidate cache to force refetch with default language.
        dispatch(
          componentAndLayoutApi.util.invalidateTags([{ type: 'Layout' }]),
        );
        navigate(`/editor/${entityType}/${entityId}`);
      }, 0);
    } else {
      // For non-default languages, set the baseUrl with language prefix first.
      dispatch(
        setConfiguration({
          baseUrl: `/${languageId}/`,
          entityType,
          entity: entityId,
          isNew: false,
          isPublished: false,
          devMode: false,
        }),
      );

      // Clear any existing cache for fresh language fetch.
      dispatch(componentAndLayoutApi.util.invalidateTags([{ type: 'Layout' }]));

      // Navigate to preview with the language info in state
      navigate(`/preview/${entityType}/${entityId}/full`, {
        state: { isLanguagePreview: true, language: languageId },
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
      </DropdownMenu.Content>
    </DropdownMenu.Root>
  );
};

export default LanguageSelector;
