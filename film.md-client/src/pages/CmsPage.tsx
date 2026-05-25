import { useEffect, useState } from "react";
import { useParams } from "react-router-dom";
import { getCmsPage, PublicCmsPage } from "../lib/storefront";
import { useLanguage } from "../contexts/LanguageContext";

export function CmsPage() {
  const { slug } = useParams<{ slug: string }>();
  const { currentLanguage } = useLanguage();
  const [page, setPage] = useState<PublicCmsPage | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!slug) {
      return;
    }

    const loadPage = async () => {
      try {
        setIsLoading(true);
        setError(null);
        const response = await getCmsPage(currentLanguage.code, slug);
        setPage(response);
        document.title = response.meta_title || response.title;

        const description = response.meta_description || response.excerpt || "";
        let metaDescription = document.querySelector<HTMLMetaElement>('meta[name="description"]');
        if (!metaDescription) {
          metaDescription = document.createElement("meta");
          metaDescription.name = "description";
          document.head.appendChild(metaDescription);
        }
        metaDescription.content = description;
      } catch (loadError) {
        setError(loadError instanceof Error ? loadError.message : "Pagina nu a putut fi încărcată.");
      } finally {
        setIsLoading(false);
      }
    };

    void loadPage();
  }, [currentLanguage.code, slug]);

  if (isLoading) {
    return <div className="container mx-auto px-4 pb-20 pt-32 text-gray-400 md:px-8">Se încarcă pagina...</div>;
  }

  if (error || !page) {
    return (
      <div className="container mx-auto px-4 pb-20 pt-32 md:px-8">
        <div className="rounded-lg border border-white/10 bg-surface p-6 text-gray-300">{error ?? "Pagina nu există."}</div>
      </div>
    );
  }

  return (
    <div className="container mx-auto max-w-4xl px-4 pb-20 pt-32 md:px-8">
      <article className="cms-content text-gray-200">
        <h1 className="mb-6 text-4xl font-bold text-white md:text-5xl">{page.title}</h1>
        {page.excerpt ? <p className="mb-8 text-lg leading-8 text-gray-400">{page.excerpt}</p> : null}
        <div dangerouslySetInnerHTML={{ __html: page.content || "" }} />
      </article>
    </div>
  );
}
