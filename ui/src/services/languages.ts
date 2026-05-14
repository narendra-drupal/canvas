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
  tagTypes: ['Languages'],
  endpoints: (builder) => ({
    getLanguages: builder.query<Language[], void>({
      query: () => '/canvas/api/v0/languages',
      transformResponse: (response: { data: Language[] }) => response.data,
      providesTags: () => [{ type: 'Languages', id: 'LIST' }],
    }),
  }),
});

export const { useGetLanguagesQuery } = languagesApi;
