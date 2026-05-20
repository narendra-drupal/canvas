import { useCallback, useEffect, useMemo, useState } from 'react';
import { useErrorBoundary } from 'react-error-boundary';
import { useLocation, useParams } from 'react-router';
import { AlertDialog, Button, Flex } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  selectDevMode,
  selectIsNew,
  selectIsPublished,
  setConfiguration,
} from '@/features/configuration/configurationSlice';
import {
  initialState as layoutInitialState,
  selectLayout,
  selectModel,
  selectUpdatePreview,
  setInitialLayoutModel,
} from '@/features/layout/layoutModelSlice';
import {
  selectPageData,
  setInitialPageData,
} from '@/features/pageData/pageDataSlice';
import {
  selectPreviewHtml,
  setHtml,
} from '@/features/pagePreview/previewSlice';
import {
  useGetLanguagePreviewMutation,
  usePostPreviewMutation,
} from '@/services/preview';
import { getViewportSizes } from '@/utils/viewports';

import styles from './PagePreview.module.css';

const PagePreview = () => {
  const dispatch = useAppDispatch();
  const layout = useAppSelector(selectLayout);
  const updatePreview = useAppSelector(selectUpdatePreview);
  const model = useAppSelector(selectModel);
  const devMode = useAppSelector(selectDevMode);
  const isNew = useAppSelector(selectIsNew);
  const isPublished = useAppSelector(selectIsPublished);
  const entity_form_fields = useAppSelector(selectPageData);
  const frameSrcDoc = useAppSelector(selectPreviewHtml);
  const [postPreview] = usePostPreviewMutation();
  const [getLanguagePreview] = useGetLanguagePreviewMutation();
  const { entityId, entityType } = useParams();
  const location = useLocation();
  const { showBoundary } = useErrorBoundary();
  const [widthVal, setWidthVal] = useState('100%');
  const { width } = useParams();
  const [linkIntercepted, setLinkIntercepted] = useState('');
  const [submissionIntercepted, setSubmissionIntercepted] = useState(false);
  // Get viewport sizes (supports theme-level customization).
  const viewportSizes = useMemo(() => getViewportSizes(), []);

  // Check if this is a language preview.
  const locationState = location.state as {
    isLanguagePreview?: boolean;
    language?: string;
  } | null;
  const isLanguagePreview = locationState?.isLanguagePreview || false;
  const language = locationState?.language;

  useEffect(() => {
    // Language preview: fetch once on mount (or when language/entity changes).
    // Intentionally excludes layout/model from deps — getLanguagePreview dispatches
    // setLayoutModel on success, which would otherwise re-trigger this effect
    // and cause an infinite request loop.
    if (!isLanguagePreview || !language || !entityType || !entityId) {
      return;
    }
    // Ensure baseUrl is set to the language prefix before fetching.
    dispatch(
      setConfiguration({
        baseUrl: `/${language}/`,
        entityType,
        entity: entityId,
        isNew,
        isPublished,
        devMode,
      }),
    );
    getLanguagePreview({ entityType, entityId }).unwrap().catch(showBoundary);

    // Reset all language-specific state when leaving the preview (back button,
    // forward to a different language, or explicit default-language selection).
    return () => {
      dispatch(setHtml(''));
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
          entityType: entityType ?? '',
          entity: entityId ?? '',
          isNew,
          isPublished,
          devMode,
        }),
      );
    };
  }, [
    isLanguagePreview,
    language,
    entityType,
    entityId,
    getLanguagePreview,
    showBoundary,
    dispatch,
    isNew,
    isPublished,
    devMode,
  ]);

  useEffect(() => {
    // Normal preview: fire when editor content changes.
    // Skip entirely during language preview to avoid conflicting requests.
    if (isLanguagePreview || !updatePreview || !entityType || !entityId) {
      return;
    }
    postPreview({ layout, model, entity_form_fields, entityId, entityType })
      .unwrap()
      .catch(showBoundary);
  }, [
    layout,
    model,
    postPreview,
    entity_form_fields,
    entityId,
    entityType,
    updatePreview,
    showBoundary,
    isLanguagePreview,
  ]);

  useEffect(() => {
    if (!width || width === 'full') {
      setWidthVal('100%');
    } else {
      viewportSizes.find((vs) => {
        if (width === vs.id) {
          setWidthVal(`${vs.width}px`);
          return true;
        }
      });
    }
  }, [width, viewportSizes]);

  useEffect(() => {
    function handlePreviewLinkClick(event: MessageEvent) {
      if (event.data && event.data.canvasPreviewClickedUrl) {
        setLinkIntercepted(event.data.canvasPreviewClickedUrl);
      }
      if (event.data && event.data.canvasPreviewFormSubmitted) {
        setSubmissionIntercepted(true);
      }
    }
    window.addEventListener('message', handlePreviewLinkClick);

    return () => {
      window.removeEventListener('message', handlePreviewLinkClick);
    };
  });

  const handleDialogOpenChange = (isOpen: boolean) => {
    if (!isOpen) {
      setLinkIntercepted('');
      setSubmissionIntercepted(false);
    }
  };

  const handleLinkOpenClick = useCallback(() => {
    window.open(linkIntercepted, '_blank');
  }, [linkIntercepted]);

  return (
    <>
      <div className={styles.PagePreviewContainer}>
        <div className={styles.controls}></div>
        <iframe
          title="Page preview"
          style={{ width: widthVal }}
          srcDoc={frameSrcDoc}
          className={styles.PagePreviewIframe}
        ></iframe>
      </div>
      <AlertDialog.Root
        open={!!linkIntercepted || submissionIntercepted}
        defaultOpen={false}
        onOpenChange={handleDialogOpenChange}
      >
        <AlertDialog.Content maxWidth="450px">
          {linkIntercepted && (
            <>
              <AlertDialog.Title>Link clicked</AlertDialog.Title>
              <AlertDialog.Description size="2" mb="4">
                You attempted to open a link in the preview but it was
                intercepted before you were navigated away from this page.
              </AlertDialog.Description>

              <AlertDialog.Description size="2">
                The link goes to <strong>{linkIntercepted}</strong>
              </AlertDialog.Description>

              <Flex gap="3" mt="4" justify="end">
                <AlertDialog.Cancel>
                  <Button variant="soft" color="gray">
                    Close
                  </Button>
                </AlertDialog.Cancel>
                <AlertDialog.Action>
                  <Button
                    variant="solid"
                    color="blue"
                    onClick={handleLinkOpenClick}
                  >
                    Open in new window
                  </Button>
                </AlertDialog.Action>
              </Flex>
            </>
          )}
          {submissionIntercepted && (
            <>
              <AlertDialog.Title>Form submitted</AlertDialog.Title>
              <AlertDialog.Description size="2" mb="4">
                You attempted to submit a form in the preview but it was
                intercepted before you were navigated away from this page.
              </AlertDialog.Description>

              <Flex gap="3" mt="4" justify="end">
                <AlertDialog.Cancel>
                  <Button variant="soft" color="gray">
                    Close
                  </Button>
                </AlertDialog.Cancel>
              </Flex>
            </>
          )}
        </AlertDialog.Content>
      </AlertDialog.Root>
    </>
  );
};

export default PagePreview;
