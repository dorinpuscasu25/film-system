import React, { useEffect, useState, createContext, useContext, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Language } from '../types';
import { MOCK_LANGUAGES, TRANSLATIONS } from '../data/mockData';

interface LanguageContextType {
  currentLanguage: Language;
  setLanguage: (code: 'en' | 'ro' | 'ru') => void;
  t: (key: string) => string;
  languages: Language[];
}

const LanguageContext = createContext<LanguageContextType | undefined>(undefined);

export function LanguageProvider({ children }: { children: ReactNode }) {
  const { i18n } = useTranslation();
  const [currentLanguage, setCurrentLanguage] = useState<Language>(() => {
    const initial = (i18n.resolvedLanguage ?? 'ro') as 'en' | 'ro' | 'ru';
    return MOCK_LANGUAGES.find((l) => l.code === initial) ?? MOCK_LANGUAGES[0];
  });

  useEffect(() => {
    const handleLangChange = (lng: string) => {
      const lang = MOCK_LANGUAGES.find((l) => l.code === lng);
      if (lang) setCurrentLanguage(lang);
    };
    i18n.on('languageChanged', handleLangChange);
    return () => {
      i18n.off('languageChanged', handleLangChange);
    };
  }, [i18n]);

  const setLanguage = (code: 'en' | 'ro' | 'ru') => {
    const lang = MOCK_LANGUAGES.find((l) => l.code === code);
    if (lang) {
      setCurrentLanguage(lang);
      void i18n.changeLanguage(code);
    }
  };

  const t = (key: string): string => {
    const translations = TRANSLATIONS[currentLanguage.code];
    return translations[key] || TRANSLATIONS['en'][key] || key;
  };

  return (
    <LanguageContext.Provider
      value={{
        currentLanguage,
        setLanguage,
        t,
        languages: MOCK_LANGUAGES,
      }}
    >
      {children}
    </LanguageContext.Provider>
  );
}

export function useLanguage() {
  const context = useContext(LanguageContext);
  if (context === undefined) {
    throw new Error('useLanguage must be used within a LanguageProvider');
  }
  return context;
}
