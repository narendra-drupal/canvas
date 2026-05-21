import { createApi } from '@reduxjs/toolkit/query/react';

import { baseQuery } from '@/services/baseQuery';

export interface Language {
  id: string;
  name: string;
  direction: 'ltr' | 'rtl';
  isDefault: boolean;
}

export const languagesApi = createApi({
  reducerPath: 'languagesApi',
  baseQuery,
  tagTypes: ['Languages', 'EntityTranslations'],
  endpoints: (builder) => ({
    getLanguages: builder.query<Language[], void>({
      query: () => '/canvas/api/v0/languages',
      transformResponse: (response: { data: Language[] }) => response.data,
      providesTags: () => [{ type: 'Languages', id: 'LIST' }],
    }),
    getEntityTranslations: builder.query<
      string[],
      { entityType: string; entityId: string }
    >({
      query: ({ entityType, entityId }) =>
        `/canvas/api/v0/entity-translations/${entityType}/${entityId}`,
      transformResponse: (response: { data: string[] }) => response.data,
      providesTags: (_result, _error, { entityType, entityId }) => [
        { type: 'EntityTranslations', id: `${entityType}:${entityId}` },
      ],
    }),
  }),
});

export const { useGetLanguagesQuery, useGetEntityTranslationsQuery } =
  languagesApi;
