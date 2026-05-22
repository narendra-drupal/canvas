import { useState, useRef } from 'react';
import { skipToken } from '@reduxjs/toolkit/query';
import { useNavigate, useParams } from 'react-router-dom';
import {
  CheckIcon,
  ChevronDownIcon,
  DotsVerticalIcon,
  ExternalLinkIcon,
  GlobeIcon,
  TrashIcon,
} from '@radix-ui/react-icons';
import { Button, Flex, Popover, Separator, Text } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { setConfiguration } from '@/features/configuration/configurationSlice';
import {
  initialState as layoutInitialState,
  setInitialLayoutModel,
} from '@/features/layout/layoutModelSlice';
import { setInitialPageData } from '@/features/pageData/pageDataSlice';
import { selectPageData } from '@/features/pageData/pageDataSlice';
import { setHtml } from '@/features/pagePreview/previewSlice';
import {
  componentAndLayoutApi,
  useGetPageLayoutQuery,
} from '@/services/componentAndLayout';
import {
  useGetLanguagesQuery,
  useGetEntityTranslationsQuery,
} from '@/services/languages';

import './LanguageSelector.css';

const LanguageSelector = () => {
  const { data: languages = [], isLoading } = useGetLanguagesQuery();
  const [selectedLanguage, setSelectedLanguage] = useState<string>('');
  const [dropdownOpen, setDropdownOpen] = useState(false);
  const [openPopoverId, setOpenPopoverId] = useState<string | null>(null);
  const navigate = useNavigate();
  const { entityType, entityId, width } = useParams();
  const dispatch = useAppDispatch();
  const dotsRefs = useRef<Record<string, HTMLButtonElement | null>>({});
  const pageData = useAppSelector(selectPageData);
  const { data: fetchedLayout } = useGetPageLayoutQuery(
    entityType && entityId ? { entityType, entityId } : skipToken,
  );
  const isPublished = fetchedLayout?.isPublished ?? false;
  const { data: availableTranslations = [] } = useGetEntityTranslationsQuery(
    { entityType: entityType!, entityId: entityId! },
    { skip: !entityType || !entityId },
  );

  // Find the default language when data is loaded.
  const defaultLanguage = languages.find((lang) => lang.isDefault);
  const currentLanguage =
    selectedLanguage || defaultLanguage?.id || languages[0]?.id || '';

  const handleLanguageChange = (languageId: string) => {
    setSelectedLanguage(languageId);
    setDropdownOpen(false);
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

      // Navigate to preview with the language info in URL query parameter and state.
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

  const handleTranslate = (languageId: string) => {
    if (entityType && entityId) {
      window.open(
        `/admin/canvas/translate/${entityType}/${entityId}/${languageId}?origin=canvas`,
        '_blank',
      );
    }
    setOpenPopoverId(null);
  };

  const handleDeleteTranslation = (languageId: string) => {
    if (entityType && entityId) {
      window.open(
        `/${languageId}/${entityType}/${entityId}/translations/delete`,
        '_blank',
      );
    }
    setOpenPopoverId(null);
  };

  const handleConfigureLanguages = () => {
    window.open('/admin/config/regional/language', '_blank');
    setDropdownOpen(false);
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
    <Popover.Root open={dropdownOpen} onOpenChange={setDropdownOpen}>
      <Popover.Trigger>
        <Button size="2" color="gray" variant="soft">
          <GlobeIcon />
          <Text>{currentLangObj?.name || 'Select Language'}</Text>
          <ChevronDownIcon width="16" height="16" />
        </Button>
      </Popover.Trigger>
      <Popover.Content align="start" className="language-selector-dropdown">
        <Flex direction="column" gap="0">
          {languages.map((language) => (
            <Flex
              key={language.id}
              align="center"
              justify="between"
              className="language-selector-row"
            >
              <button
                className="language-selector-item"
                onClick={() => handleLanguageChange(language.id)}
              >
                <Flex align="center" gap="2">
                  {availableTranslations.includes(language.id) ? (
                    <CheckIcon width="16" height="16" />
                  ) : (
                    <span style={{ width: 16 }} />
                  )}
                  <Text size="2">
                    {language.name}
                    {language.isDefault && ' (Default)'}
                  </Text>
                </Flex>
              </button>
              <Popover.Root
                open={openPopoverId === language.id}
                onOpenChange={(open) =>
                  setOpenPopoverId(open ? language.id : null)
                }
              >
                <Popover.Trigger>
                  <button
                    ref={(el) => { dotsRefs.current[language.id] = el; }}
                    className="language-selector-dots-btn"
                    onClick={(e) => {
                      e.stopPropagation();
                    }}
                  >
                    <DotsVerticalIcon width="14" height="14" />
                  </button>
                </Popover.Trigger>
                <Popover.Content
                  side="left"
                  sideOffset={160}
                  align="start"
                  avoidCollisions={false}
                  className="language-selector-popover"
                >
                  <Flex direction="column" gap="1">
                    <Text size="2" weight="medium" className="language-selector-popover-title">
                      {pageData?.title || 'Untitled'} ({language.name})
                    </Text>
                    <Separator size="4" my="1" />
                    {/* @todo 🔥🔥🐛🐛 "Edit translation" was showing for unpublished entities and default language */}
                    {isPublished && !language.isDefault && (
                      <button
                        className="language-selector-popover-item"
                        onClick={() => handleTranslate(language.id)}
                      >
                        <ExternalLinkIcon width="14" height="14" />
                        <Text size="2">Edit translation</Text>
                      </button>
                    )}
                    {/* @todo 🔥🔥🐛🐛 Delete button was showing for languages without translations (from merged patch) */}
                    {availableTranslations.includes(language.id) && !language.isDefault && (
                      <button
                        className="language-selector-popover-item language-selector-popover-item--red"
                        onClick={() => handleDeleteTranslation(language.id)}
                      >
                        <TrashIcon width="14" height="14" />
                        <Text size="2">Delete translation</Text>
                      </button>
                    )}
                  </Flex>
                </Popover.Content>
              </Popover.Root>
            </Flex>
          ))}
          <Separator size="4" my="2" />
          <button
            className="language-selector-item"
            onClick={handleConfigureLanguages}
          >
            <Flex align="center" gap="2">
              <ExternalLinkIcon width="14" height="14" />
              <Text size="2">Configure languages</Text>
            </Flex>
          </button>
        </Flex>
      </Popover.Content>
    </Popover.Root>
  );
};

export default LanguageSelector;
