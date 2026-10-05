import {
  ActivityIcon,
  DatabaseBackupIcon,
  FileTextIcon,
  LayoutTemplateIcon,
  MenuIcon,
  SearchIcon,
  SettingsIcon,
} from "lucide-react";
import { SectionLayout } from "../components/shared/SectionLayout";
import { useAdmin } from "../hooks/useAdmin";
import { Backups } from "./Backups";
import { BunnyHealth } from "./BunnyHealth";
import { CMSPages } from "./CMSPages";
import { CMSSettings } from "./CMSSettings";
import { HomeCuration } from "./HomeCuration";
import { Menus } from "./Menus";
import { SearchDiscovery } from "./SearchDiscovery";
import { SeoSettings } from "./SeoSettings";

/**
 * One entry point for the settings that used to occupy eight separate sidebar
 * rows. Each panel is the existing page, mounted only when its tab is selected.
 *
 * The original routes still work, so bookmarks and links from elsewhere in the
 * admin keep resolving.
 */
export function SettingsHub() {
  const { can } = useAdmin();

  return (
    <SectionLayout
      title="Setări"
      description="Conținutul public al site-ului și starea tehnică a platformei."
      navLabel="Setări"
      tabs={[
        {
          id: "home",
          label: "Pagina principală",
          icon: LayoutTemplateIcon,
          show: can("settings.edit_home_curation"),
        },
        {
          id: "pages",
          label: "Pagini",
          icon: FileTextIcon,
          show: can("cms.view"),
        },
        {
          id: "menus",
          label: "Meniuri",
          icon: MenuIcon,
          show: can("cms.view"),
        },
        {
          id: "seo",
          label: "SEO",
          icon: SearchIcon,
          show: can("settings.view") || can("settings.edit_home_curation"),
        },
        {
          id: "search",
          label: "Căutare",
          icon: SearchIcon,
          show: can("settings.edit_search_config"),
        },
        {
          id: "cms",
          label: "Setări pagini",
          icon: SettingsIcon,
          show: can("cms.view") && can("settings.edit_home_curation"),
        },
        {
          id: "bunny",
          label: "Stare Bunny",
          icon: ActivityIcon,
          show: can("settings.edit_home_curation"),
        },
        {
          id: "backups",
          label: "Backup-uri",
          icon: DatabaseBackupIcon,
          show: can("settings.manage_backups"),
        },
      ]}
    >
      {(tab) => {
        switch (tab) {
          case "home":
            return <HomeCuration />;
          case "pages":
            return <CMSPages />;
          case "menus":
            return <Menus />;
          case "seo":
            return <SeoSettings />;
          case "search":
            return <SearchDiscovery />;
          case "cms":
            return <CMSSettings />;
          case "bunny":
            return <BunnyHealth />;
          case "backups":
            return <Backups />;
          default:
            return null;
        }
      }}
    </SectionLayout>
  );
}
