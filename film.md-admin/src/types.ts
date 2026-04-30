export interface AdminRole {
  id: number;
  name: string;
  description: string | null;
  is_system: boolean;
  is_default: boolean;
  admin_panel_access: boolean;
  permission_codes: string[];
  permission_ids: number[];
}

export interface AdminPermission {
  id: number;
  code: string;
  name: string;
  group: string;
  description: string | null;
  is_system: boolean;
}

export interface AdminUser {
  id: number;
  name: string;
  email: string;
  preferred_locale: "en" | "ro" | "ru";
  status: "active" | "suspended";
  avatar_url: string | null;
  last_seen_at: string | null;
  created_at: string | null;
  roles: AdminRole[];
  permission_codes: string[];
  admin_panel_access: boolean;
  assigned_content_ids: number[];
  assigned_contents: Array<{
    id: number;
    title: string | null;
    slug: string | null;
  }>;
}

export interface AdminInvitation {
  id: number;
  email: string;
  name: string | null;
  status: "pending" | "accepted" | "expired";
  role_ids: number[];
  role_names: string[];
  expires_at: string | null;
  accepted_at: string | null;
  created_at: string | null;
}

export interface DashboardStats {
  users_total: number;
  admins_total: number;
  roles_total: number;
  pending_invitations: number;
  total_revenue_amount: number;
  period_revenue_amount: number;
  orders_total: number;
  period_orders_count: number;
  paid_orders_count: number;
  free_claims_count: number;
  unique_buyers_count: number;
  average_order_value: number;
  active_entitlements_count: number;
  wallet_balance_total: number;
  total_views: number;
  total_watch_time_seconds: number;
  total_bandwidth_gb: number;
  current_month_costs_usd: number;
  current_month_profit_usd: number;
}

export interface DashboardRange {
  value: "7days" | "30days" | "3months";
  label: string;
  days: number;
  from: string;
  to: string;
}

export interface DashboardBreakdown {
  rental_orders_count: number;
  lifetime_orders_count: number;
  free_orders_count: number;
  rental_revenue_amount: number;
  lifetime_revenue_amount: number;
}

export interface DashboardTimelinePoint {
  date: string;
  label: string;
  revenue_amount: number;
  orders_count: number;
  free_claims_count: number;
}

export interface DashboardTransactionUser {
  id: number | null;
  name: string | null;
  email: string | null;
}

export interface DashboardTransactionContent {
  id: number;
  slug: string;
  title: string;
  type: AdminContentType;
  poster_url: string | null;
}

export interface DashboardTransactionOffer {
  id: number | null;
  name: string | null;
  quality: string | null;
  offer_type: AdminOfferType | null;
}

export interface DashboardTransaction {
  id: number;
  type: "purchase" | "refund" | "top_up" | "welcome_bonus" | "adjustment";
  type_label: string;
  amount: number;
  amount_absolute: number;
  balance_after: number;
  currency: string;
  description: string | null;
  processed_at: string | null;
  user: DashboardTransactionUser;
  content: DashboardTransactionContent | null;
  offer: DashboardTransactionOffer;
}

export interface DashboardTopTitle {
  content_id: number | null;
  slug: string | null;
  title: string;
  type: AdminContentType | null;
  poster_url: string | null;
  orders_count: number;
  paid_orders_count: number;
  free_claims_count: number;
  unique_buyers_count: number;
  revenue_amount: number;
}

export interface DashboardSummary {
  catalog_titles_total: number;
  published_titles_total: number;
  buyers_total: number;
}

export interface DashboardAnalyticsTimelinePoint {
  date: string;
  label: string;
  views: number;
  watch_time_seconds: number;
  bandwidth_gb: number;
}

export interface DashboardCountryBreakdown {
  country_code: string;
  views: number;
  watch_time_seconds: number;
  bandwidth_gb: number;
}

export interface DashboardCostOverview {
  storage_cost_usd: number;
  delivery_cost_usd: number;
  drm_cost_usd: number;
  revenue_usd: number;
  profit_usd: number;
}

export interface DashboardResponse {
  range: DashboardRange;
  stats: DashboardStats;
  breakdown: DashboardBreakdown;
  sales_timeline: DashboardTimelinePoint[];
  recent_transactions: DashboardTransaction[];
  recent_sales: DashboardTransaction[];
  top_titles: DashboardTopTitle[];
  summary: DashboardSummary;
  analytics_timeline: DashboardAnalyticsTimelinePoint[];
  country_breakdown: DashboardCountryBreakdown[];
  cost_overview: DashboardCostOverview;
}

export interface CostSettingsVersion {
  id: number;
  storage_cost_per_gb_day: number;
  delivery_cost_per_gb: number;
  drm_cost_per_license: number;
  usd_to_mdl_rate: number;
  effective_from: string | null;
  effective_until: string | null;
  is_active: boolean;
  created_at: string | null;
}

export interface VideoMonthlyCostItem {
  id: number;
  content_id: number | null;
  content_title: string | null;
  quality: string | null;
  month: string;
  storage_cost_usd: number;
  delivery_cost_usd: number;
  drm_cost_usd: number;
  revenue_usd: number;
  profit_usd: number;
  is_locked: boolean;
}

export interface CreatorStatementItem {
  id: number;
  creator_id: number | null;
  creator_name: string | null;
  month: string;
  revenue_usd: number;
  costs_usd: number;
  payout_usd: number;
  profit_usd: number;
  is_locked: boolean;
}

export interface CostSettingsCreatorOption {
  id: number;
  name: string;
  email: string | null;
  company_name: string | null;
}

export interface CostSettingsResponse {
  current: CostSettingsVersion | null;
  versions: CostSettingsVersion[];
  monthly_costs: VideoMonthlyCostItem[];
  creator_statements: CreatorStatementItem[];
  creators: CostSettingsCreatorOption[];
}

export interface CostSettingsPayload {
  storage_cost_per_gb_day: number;
  delivery_cost_per_gb: number;
  drm_cost_per_license: number;
  usd_to_mdl_rate: number;
}

export interface ContentFinancialsRow {
  format_id: number | null;
  quality: string | null;
  format_type: string | null;
  storage_cost_usd: number;
  delivery_cost_usd: number;
  drm_cost_usd: number;
  revenue_usd: number;
  profit_usd: number;
  is_locked: boolean;
}

export interface ContentFinancialsTotals {
  storage_cost_usd: number;
  delivery_cost_usd: number;
  drm_cost_usd: number;
  revenue_usd: number;
  profit_usd: number;
}

export interface ContentFinancialsMonth {
  month: string;
  rows: ContentFinancialsRow[];
  totals: ContentFinancialsTotals;
}

export interface FinancialSummaryMonthlyChartItem {
  month: string;
  total_cost_mdl: number;
  revenue_mdl: number;
  profit_mdl: number;
}

export interface FinancialSummaryTopMovie {
  content_id: number;
  title: string | null;
  slug: string | null;
  poster_url: string | null;
  cost_mdl: number;
  revenue_mdl: number;
  profit_mdl: number;
}

export interface FinancialSummaryResponse {
  currency: string;
  usd_to_mdl_rate: number;
  current_month: { label: string; cost_mdl: number };
  previous_month: { label: string; cost_mdl: number };
  total_costs_mdl: number;
  total_revenue_mdl: number;
  total_profit_mdl: number;
  monthly_chart: FinancialSummaryMonthlyChartItem[];
  top_movies_current_month: FinancialSummaryTopMovie[];
}

export interface ContentFinancialsResponse {
  content_id: number;
  content_title: string | null;
  usd_to_mdl_rate: number;
  months: ContentFinancialsMonth[];
  totals: ContentFinancialsTotals;
  totals_mdl: {
    storage_cost: number;
    delivery_cost: number;
    drm_cost: number;
    total_cost: number;
    revenue: number;
    profit: number;
  };
  sales_count: number;
  offers: Array<{
    id: number;
    quality: string;
    price: number;
    currency: string;
    is_active: boolean;
  }>;
  formats: Array<{
    id: number;
    quality: string;
    format_type: string;
    bunny_library_id: string;
    bunny_video_id: string;
    is_active: boolean;
  }>;
}

export interface ExportJobItem {
  id: number;
  format: string;
  scope: string;
  status: string;
  file_path: string | null;
  file_name?: string | null;
  mime_type?: string | null;
  error_message?: string | null;
  filters: Record<string, unknown>;
  requested_by: string | null;
  created_at: string | null;
}

export interface ExportJobsResponse {
  items: ExportJobItem[];
}

export interface ExportJobPayload {
  format: "excel" | "pdf" | "json";
  scope: string;
  filters?: Record<string, unknown>;
}

export interface PlaybackOpsStats {
  active_streams: number;
  completed_today: number;
  total_watch_time_seconds: number;
}

export interface PlaybackOpsSession {
  id: number;
  user_name: string | null;
  user_email: string | null;
  content_title: string | null;
  quality: string | null;
  country_code: string | null;
  device_type: string | null;
  status: string;
  started_at: string | null;
  ended_at: string | null;
  watch_time_seconds: number;
  max_position_seconds: number;
}

export interface PlaybackOpsResponse {
  stats: PlaybackOpsStats;
  sessions: PlaybackOpsSession[];
}

export interface AdminAdCreative {
  id: number;
  name: string;
  media_url: string;
  mime_type: string | null;
  duration_seconds: number | null;
  width: number | null;
  height: number | null;
  is_active: boolean;
}

export interface AdminAdTargetingRule {
  id: number;
  country_code: string | null;
  allowed_group: string | null;
  content_id: number | null;
  content_title: string | null;
  is_include_rule: boolean;
}

export interface AdminAdCampaignStats {
  impressions: number;
  clicks: number;
  completes: number;
}

export interface AdminAdCampaign {
  id: number;
  name: string;
  company_name: string | null;
  vast_tag_url: string | null;
  click_through_url: string | null;
  placement: string;
  status: string;
  bid_amount: number;
  skip_offset_seconds: number | null;
  starts_at: string | null;
  ends_at: string | null;
  is_active: boolean;
  creatives: AdminAdCreative[];
  targeting_rules: AdminAdTargetingRule[];
  stats: AdminAdCampaignStats;
}

export interface AdCampaignOptionContent {
  id: number;
  title: string;
  slug: string;
}

export interface AdCampaignOptions {
  placements: string[];
  statuses: string[];
  allowed_groups: string[];
  contents: AdCampaignOptionContent[];
}

export interface AdCampaignsResponse {
  items: AdminAdCampaign[];
  options: AdCampaignOptions;
}

export interface AdCampaignPayload {
  name: string;
  company_name?: string | null;
  vast_tag_url?: string | null;
  click_through_url?: string | null;
  placement: string;
  status: string;
  bid_amount?: number | null;
  skip_offset_seconds?: number | null;
  starts_at?: string | null;
  ends_at?: string | null;
  is_active?: boolean;
  creatives?: Array<{
    name: string;
    media_url: string;
    mime_type?: string | null;
    duration_seconds?: number | null;
    width?: number | null;
    height?: number | null;
    is_active?: boolean;
  }>;
  targeting_rules?: Array<{
    country_code?: string | null;
    allowed_group?: string | null;
    content_id?: number | null;
    is_include_rule?: boolean;
  }>;
}

export type TaxonomyLocale = "ro" | "ru" | "en";

export type TaxonomyType = "genre" | "collection" | "tag" | "badge";

export interface LocalizedText {
  ro: string;
  ru: string;
  en: string;
}

export interface TaxonomyLocaleOption {
  value: TaxonomyLocale;
  label: string;
}

export interface TaxonomyTypeOption {
  value: TaxonomyType;
  label: string;
}

export interface AdminTaxonomy {
  id: number;
  type: TaxonomyType;
  slug: string;
  active: boolean;
  color: string | null;
  content_count: number;
  sort_order: number;
  name: LocalizedText;
  description: LocalizedText;
  localized_name: string;
  localized_description: string | null;
  created_at: string | null;
  updated_at: string | null;
}

export interface TaxonomyIndexResponse {
  types: TaxonomyTypeOption[];
  locales: TaxonomyLocaleOption[];
  taxonomies: Partial<Record<TaxonomyType, AdminTaxonomy[]>>;
}

export interface TaxonomyPayload {
  type: TaxonomyType;
  slug: string;
  active: boolean;
  color?: string | null;
  content_count?: number;
  sort_order?: number;
  name: LocalizedText;
  description: LocalizedText;
}

export type HomeSectionType = "hero_slider" | "content_carousel";
export type HomeSectionSourceMode = "manual" | "dynamic";
export type HomeSectionAccessMode = "all" | "free" | "paid";
export type HomeSectionSortMode =
  | "manual"
  | "release_year_desc"
  | "release_year_asc"
  | "published_desc"
  | "imdb_desc"
  | "platform_desc"
  | "title_asc";
export type HomeSectionMatchStrategy = "any" | "all";

export interface HomeCurationBadgeOption {
  id: string;
  slug: string;
  label: string;
  color: string | null;
}

export interface HomeCurationContentOption {
  id: number;
  slug: string;
  type: AdminContentType;
  status: AdminContentStatus;
  title: string;
  original_title: string;
  release_year: number | null;
  poster_url: string | null;
  backdrop_url: string | null;
  hero_desktop_url: string | null;
  hero_mobile_url: string | null;
  lowest_price: number;
  currency: string;
  is_featured: boolean;
  is_trending: boolean;
  genres: string[];
  collections: string[];
  tags: string[];
  badges: HomeCurationBadgeOption[];
}

export interface HomeCurationTaxonomyOption {
  id: number;
  type: TaxonomyType;
  slug: string;
  color: string | null;
  name: LocalizedText;
  localized_name: string;
}

export interface HomeCurationRuleFilters {
  taxonomy_ids: number[];
  content_types: AdminContentType[];
  access: HomeSectionAccessMode;
  sort_mode: HomeSectionSortMode;
  matching_strategy: HomeSectionMatchStrategy;
  featured_only: boolean;
  trending_only: boolean;
}

export interface HomeCurationHeroSlide {
  id: string;
  content_id: number;
  active: boolean;
  sort_order: number;
  desktop_image_url: string | null;
  mobile_image_url: string | null;
  eyebrow: LocalizedText;
  title: LocalizedText;
  description: LocalizedText;
  primary_cta_label: LocalizedText;
  secondary_cta_label: LocalizedText;
  localized_eyebrow?: string | null;
  localized_title?: string | null;
  localized_description?: string | null;
  localized_primary_cta_label?: string | null;
  localized_secondary_cta_label?: string | null;
  content?: HomeCurationContentOption | null;
}

export interface HomeCurationSection {
  id: number | null;
  name: string;
  section_type: HomeSectionType;
  active: boolean;
  sort_order: number;
  title: LocalizedText;
  subtitle: LocalizedText;
  localized_title?: string | null;
  localized_subtitle?: string | null;
  source_mode: HomeSectionSourceMode | null;
  limit: number | null;
  content_ids: number[];
  selected_content: HomeCurationContentOption[];
  rule_filters: HomeCurationRuleFilters;
  hero_slides: HomeCurationHeroSlide[];
  meta: Record<string, unknown>;
}

export interface HomeCurationOptions {
  locales: Array<SelectOption<TaxonomyLocale>>;
  section_types: Array<SelectOption<HomeSectionType>>;
  source_modes: Array<SelectOption<HomeSectionSourceMode>>;
  sort_modes: Array<SelectOption<HomeSectionSortMode>>;
  access_modes: Array<SelectOption<HomeSectionAccessMode>>;
  matching_strategies: Array<SelectOption<HomeSectionMatchStrategy>>;
  content_types: Array<SelectOption<AdminContentType>>;
  taxonomies: Partial<Record<TaxonomyType, HomeCurationTaxonomyOption[]>>;
  contents: HomeCurationContentOption[];
}

export interface HomeCurationResponse {
  sections: HomeCurationSection[];
  options: HomeCurationOptions;
}

export type AdminContentType = "movie" | "series";
export type AdminContentStatus = "draft" | "ready" | "published" | "archived";
export type AdminOfferType = "free" | "rental" | "lifetime";
export type AdminOfferAvailabilityStatus = "active" | "inactive" | "scheduled" | "expired";

export interface SelectOption<TValue extends string = string> {
  value: TValue;
  label: string;
}

export interface AdminContentTaxonomyOption {
  id: number;
  type: TaxonomyType;
  slug: string;
  name: LocalizedText;
  localized_name: string;
  color: string | null;
}

export interface AdminContentCastMember {
  id: string;
  name: string;
  credit_type: string;
  credit_type_label?: string | null;
  character_name: LocalizedText;
  localized_character_name?: string | null;
  role?: string | null;
  avatar_url: string | null;
  sort_order: number;
}

export interface AdminContentCrewMember {
  id: string;
  name: string;
  credit_type: string;
  credit_type_label?: string | null;
  job_title: LocalizedText;
  localized_job_title?: string | null;
  job?: string | null;
  avatar_url: string | null;
  sort_order: number;
}

export interface AdminContentVideo {
  id: string;
  type: "trailer" | "teaser" | "clip" | "extra" | "behind_scenes" | "interview";
  title: LocalizedText;
  localized_title?: string | null;
  description: LocalizedText | null;
  localized_description?: string | null;
  video_url: string;
  thumbnail_url: string | null;
  duration_seconds: number | null;
  is_primary: boolean;
  sort_order: number;
}

export interface AdminContentEpisode {
  id: string;
  episode_number: number;
  title: LocalizedText;
  localized_title?: string | null;
  description: LocalizedText | null;
  localized_description?: string | null;
  runtime_minutes: number | null;
  thumbnail_url: string | null;
  backdrop_url: string | null;
  video_url: string | null;
  trailer_url: string | null;
  sort_order: number;
}

export interface AdminContentSeason {
  id: string;
  season_number: number;
  title: LocalizedText | null;
  localized_title?: string | null;
  description: LocalizedText | null;
  localized_description?: string | null;
  poster_url: string | null;
  sort_order: number;
  episodes: AdminContentEpisode[];
}

export interface AdminContentFormat {
  id: number;
  quality: string;
  format_type: "main" | "trailer";
  bunny_library_id: string;
  bunny_video_id: string;
  stream_url: string | null;
  token_path: string | null;
  drm_policy: string;
  is_active: boolean;
  is_default: boolean;
  sort_order: number;
  meta: Record<string, unknown>;
}

export interface AdminRightsWindow {
  id: number;
  content_format_id: number | null;
  content_format_quality: string | null;
  country_code: string | null;
  is_allowed: boolean;
  starts_at: string | null;
  ends_at: string | null;
  meta: Record<string, unknown>;
}

export interface AdminSubtitleTrack {
  id: number;
  content_format_id: number | null;
  content_format_quality: string | null;
  locale: TaxonomyLocale;
  label: string;
  file_url: string;
  is_default: boolean;
  sort_order: number;
}

export interface AdminPremiereEvent {
  id: number;
  title: string;
  starts_at: string | null;
  ends_at: string | null;
  is_active: boolean;
  is_public: boolean;
  meta: Record<string, unknown>;
}

export interface AdminContentCreator {
  id: number;
  name: string;
  email: string | null;
  company_name: string | null;
  platform_fee_percent: number;
  assignment_role: string;
  is_primary: boolean;
}

export interface AdminUserContentOption {
  id: number;
  title: string;
  slug: string;
}

export interface AdminContent {
  id: number;
  type: AdminContentType;
  slug: string;
  default_locale: TaxonomyLocale;
  status: AdminContentStatus;
  original_title: string;
  title: LocalizedText;
  tagline: LocalizedText;
  short_description: LocalizedText;
  description: LocalizedText;
  editor_notes: LocalizedText;
  meta_title: LocalizedText;
  meta_description: LocalizedText;
  localized_title: string;
  localized_short_description: string | null;
  release_year: number | null;
  country_code: string | null;
  country_name: string | null;
  imdb_rating: number | null;
  platform_rating: number | null;
  runtime_minutes: number | null;
  age_rating: string | null;
  poster_url: string;
  backdrop_url: string;
  hero_desktop_url: string | null;
  hero_mobile_url: string | null;
  trailer_url: string | null;
  preview_images: string[];
  cast: AdminContentCastMember[];
  crew: AdminContentCrewMember[];
  videos: AdminContentVideo[];
  seasons: AdminContentSeason[];
  content_formats: AdminContentFormat[];
  rights_windows: AdminRightsWindow[];
  subtitle_tracks: AdminSubtitleTrack[];
  premiere_events: AdminPremiereEvent[];
  creators: AdminContentCreator[];
  creator_ids: number[];
  seasons_count: number;
  episodes_count: number;
  subtitle_locales: TaxonomyLocale[];
  available_qualities: string[];
  is_featured: boolean;
  is_trending: boolean;
  is_free: boolean;
  lowest_price: number;
  price_amount: number;
  currency: string;
  rental_days: number | null;
  offers_count: number;
  active_offers_count: number;
  sort_order: number;
  canonical_url: string | null;
  published_at: string | null;
  taxonomy_ids: number[];
  genres: AdminContentTaxonomyOption[];
  collections: AdminContentTaxonomyOption[];
  tags: AdminContentTaxonomyOption[];
  badges: AdminContentTaxonomyOption[];
  offers: AdminOffer[];
  created_at: string | null;
  updated_at: string | null;
}

export interface AdminContentFilters {
  types: SelectOption<AdminContentType>[];
  statuses: SelectOption<AdminContentStatus>[];
  countries: SelectOption[];
}

export interface AdminContentOptions {
  locales: TaxonomyLocaleOption[];
  types: SelectOption<AdminContentType>[];
  statuses: SelectOption<AdminContentStatus>[];
  countries: SelectOption[];
  age_ratings: string[];
  quality_options: string[];
  offer_types?: SelectOption<AdminOfferType>[];
  video_types?: SelectOption<AdminContentVideo["type"]>[];
  cast_credit_types?: SelectOption[];
  crew_credit_types?: SelectOption[];
  format_types?: SelectOption<AdminContentFormat["format_type"]>[];
  taxonomies: Partial<Record<TaxonomyType, AdminContentTaxonomyOption[]>>;
}

export interface AdminOffer {
  id: number;
  content_id: number;
  content_slug: string | null;
  content_title: string | null;
  content_type: AdminContentType | null;
  poster_url: string | null;
  name: string;
  offer_type: AdminOfferType;
  offer_type_label: string;
  quality: string;
  currency: string;
  price_amount: number;
  playback_url: string | null;
  rental_days: number | null;
  access_label: string;
  is_active: boolean;
  is_currently_available: boolean;
  availability_status: AdminOfferAvailabilityStatus;
  starts_at: string | null;
  ends_at: string | null;
  sort_order: number;
  created_at: string | null;
  updated_at: string | null;
}

export interface AdminContentReview {
  id: number;
  content_id: number;
  content_slug: string | null;
  content_title: string | null;
  content_type: AdminContentType | null;
  user_id: number;
  user_name: string;
  user_email: string | null;
  user_avatar: string;
  rating: number;
  comment: string;
  status: "published" | "hidden";
  locale: string | null;
  created_at: string | null;
  updated_at: string | null;
}

export interface AdminContentReviewsResponse {
  items: AdminContentReview[];
  stats: {
    total: number;
    published: number;
    hidden: number;
  };
  pagination: {
    page: number;
    per_page: number;
    total: number;
  };
}

export interface OfferPayload {
  content_id: number;
  name?: string;
  offer_type: AdminOfferType;
  quality: string;
  currency?: string;
  price_amount: number;
  playback_url?: string | null;
  rental_days?: number | null;
  is_active: boolean;
  starts_at?: string | null;
  ends_at?: string | null;
  sort_order?: number;
}

export interface OfferIndexResponse {
  items: AdminOffer[];
  stats: {
    total_offers: number;
    active_offers: number;
    rental_offers: number;
    lifetime_offers: number;
  };
  filters: {
    types: SelectOption<AdminOfferType>[];
    qualities: SelectOption[];
    contents: SelectOption[];
  };
}

export interface ContentPayload {
  type: AdminContentType;
  slug: string;
  default_locale: TaxonomyLocale;
  status: AdminContentStatus;
  original_title: string;
  title: LocalizedText;
  tagline: LocalizedText;
  short_description: LocalizedText;
  description: LocalizedText;
  editor_notes: LocalizedText;
  meta_title: LocalizedText;
  meta_description: LocalizedText;
  release_year?: number | null;
  country_code?: string | null;
  imdb_rating?: number | null;
  platform_rating?: number | null;
  runtime_minutes?: number | null;
  age_rating?: string | null;
  poster_url: string;
  backdrop_url: string;
  hero_desktop_url?: string | null;
  hero_mobile_url?: string | null;
  trailer_url?: string | null;
  preview_images?: string[];
  cast_members?: Array<{
    id?: string;
    name: string;
    credit_type: string;
    character_name: LocalizedText;
    avatar_url?: string | null;
    sort_order?: number;
  }>;
  crew_members?: Array<{
    id?: string;
    name: string;
    credit_type: string;
    job_title: LocalizedText;
    avatar_url?: string | null;
    sort_order?: number;
  }>;
  videos?: Array<{
    id?: string;
    type: AdminContentVideo["type"];
    title: LocalizedText;
    description?: LocalizedText | null;
    video_url: string;
    thumbnail_url?: string | null;
    duration_seconds?: number | null;
    is_primary?: boolean;
    sort_order?: number;
  }>;
  seasons?: Array<{
    id?: string;
    season_number: number;
    title?: LocalizedText | null;
    description?: LocalizedText | null;
    poster_url?: string | null;
    sort_order?: number;
    episodes?: Array<{
      id?: string;
      episode_number: number;
      title: LocalizedText;
      description?: LocalizedText | null;
      runtime_minutes?: number | null;
      thumbnail_url?: string | null;
      backdrop_url?: string | null;
      video_url?: string | null;
      trailer_url?: string | null;
      sort_order?: number;
    }>;
  }>;
  subtitle_locales?: TaxonomyLocale[];
  content_formats?: Array<{
    id?: number;
    quality: string;
    format_type: AdminContentFormat["format_type"];
    bunny_library_id: string;
    bunny_video_id: string;
    stream_url?: string | null;
    token_path?: string | null;
    drm_policy?: string | null;
    is_active?: boolean;
    is_default?: boolean;
    sort_order?: number;
    meta?: Record<string, unknown>;
  }>;
  rights_windows?: Array<{
    id?: number;
    content_format_quality?: string | null;
    country_code?: string | null;
    is_allowed?: boolean;
    starts_at?: string | null;
    ends_at?: string | null;
    meta?: Record<string, unknown>;
  }>;
  subtitle_tracks?: Array<{
    id?: number;
    content_format_quality?: string | null;
    locale: TaxonomyLocale;
    label: string;
    file_url: string;
    is_default?: boolean;
    sort_order?: number;
  }>;
  premiere_events?: Array<{
    id?: number;
    title: string;
    starts_at: string;
    ends_at?: string | null;
    is_active?: boolean;
    is_public?: boolean;
    meta?: Record<string, unknown>;
  }>;
  creator_ids?: number[];
  available_qualities: string[];
  is_featured: boolean;
  is_trending: boolean;
  is_free: boolean;
  price_amount?: number | null;
  currency?: string;
  rental_days?: number | null;
  sort_order?: number;
  canonical_url?: string | null;
  taxonomy_ids?: number[];
}

export interface AuditLogItem {
  id: number;
  timestamp: string | null;
  user: string;
  user_id: number | null;
  action: string;
  target: string;
  details: string;
  entity_type: string;
  entity_id: string | null;
  ip_address: string | null;
  payload: Record<string, unknown>;
}

export interface AuditLogsResponse {
  items: AuditLogItem[];
}
