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
  endpoints: (builder) => ({
    getLanguages: builder.query<Language[], void>({
      query: () => '/canvas/api/v0/languages',
    }),
  }),
});

export const { useGetLanguagesQuery } = languagesApi;
