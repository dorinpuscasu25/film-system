import React, { useState, createContext, useContext } from 'react';
import { Language } from '../types';
import { MOCK_LANGUAGES, TRANSLATIONS } from '../data/mockData';
interface LanguageContextType {
  currentLanguage: Language;
  setLanguage: (code: 'en' | 'ro' | 'ru') => void;
  t: (key: string) => string;
  languages: Language[];
}
const LanguageContext = createContext<LanguageContextType | undefined>(
  undefined
);
export function LanguageProvider({ children }: {children: ReactNode;}) {
  const [currentLanguage, setCurrentLanguage] = useState<Language>(
    MOCK_LANGUAGES[0]
  );
  const setLanguage = (code: 'en' | 'ro' | 'ru') => {
    const lang = MOCK_LANGUAGES.find((l) => l.code === code);
    if (lang) setCurrentLanguage(lang);
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
        languages: MOCK_LANGUAGES
      }}>
      
      {children}
    </LanguageContext.Provider>);

}
export function useLanguage() {
  const context = useContext(LanguageContext);
  if (context === undefined) {
    throw new Error('useLanguage must be used within a LanguageProvider');
  }
  return context;
}