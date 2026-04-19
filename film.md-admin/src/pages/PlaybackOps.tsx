import React from "react";
import {
  ActivityIcon,
  MegaphoneIcon,
  PlayCircleIcon,
  ShieldIcon,
  Trash2Icon,
} from "lucide-react";
import { StatsCard } from "../components/shared/StatsCard";
import { Tabs } from "../components/shared/Tabs";
import { Button } from "../components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "../components/ui/card";
import { Input } from "../components/ui/input";
import { Switch } from "../components/ui/switch";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "../components/ui/table";
import { adminApi } from "../lib/api";
import { AdCampaignsResponse, PlaybackOpsResponse } from "../types";
import { useAdmin } from "../hooks/useAdmin";

interface CampaignDraftCreative {
  name: string;
  media_url: string;
  mime_type: string;
  duration_seconds: string;
  width: string;
  height: string;
  is_active: boolean;
}

interface CampaignDraftRule {
  country_code: string;
  allowed_group: string;
  content_id: string;
  is_include_rule: boolean;
}

interface CampaignDraft {
  id: number | null;
  name: string;
  company_name: string;
  vast_tag_url: string;
  click_through_url: string;
  placement: string;
  status: string;
  bid_amount: string;
  skip_offset_seconds: string;
  starts_at: string;
  ends_at: string;
  is_active: boolean;
  creatives: CampaignDraftCreative[];
  targeting_rules: CampaignDraftRule[];
}

const EMPTY_OPS: PlaybackOpsResponse = {
  stats: {
    active_streams: 0,
    completed_today: 0,
    total_watch_time_seconds: 0,
  },
  sessions: [],
};

const EMPTY_CAMPAIGNS: AdCampaignsResponse = {
  items: [],
  options: {
    placements: ["pre_roll", "mid_roll", "post_roll"],
    statuses: ["draft", "active", "paused", "completed"],
    allowed_groups: ["movies", "trailers"],
    contents: [],
  },
};

function formatDate(value: string | null) {
  return value ? new Date(value).toLocaleString() : "Fără dată";
}

function formatDuration(seconds: number) {
  const hours = Math.floor(seconds / 3600);
  const minutes = Math.floor((seconds % 3600) / 60);
  const remainingSeconds = seconds % 60;

  if (hours > 0) {
    return `${hours}h ${minutes}m`;
  }

  if (minutes > 0) {
    return `${minutes}m ${remainingSeconds}s`;
  }

  return `${remainingSeconds}s`;
}

function toDateTimeLocal(value: string | null) {
  if (!value) {
    return "";
  }

  return value.slice(0, 16);
}

function createEmptyDraft(options: AdCampaignsResponse["options"]): CampaignDraft {
  return {
    id: null,
    name: "",
    company_name: "",
    vast_tag_url: "",
    click_through_url: "",
    placement: options.placements[0] ?? "pre_roll",
    status: options.statuses[0] ?? "draft",
    bid_amount: "",
    skip_offset_seconds: "",
    starts_at: "",
    ends_at: "",
    is_active: true,
    creatives: [],
    targeting_rules: [],
  };
}

function mapCampaignToDraft(campaign: AdCampaignsResponse["items"][number]): CampaignDraft {
  return {
    id: campaign.id,
    name: campaign.name,
    company_name: campaign.company_name ?? "",
    vast_tag_url: campaign.vast_tag_url ?? "",
    click_through_url: campaign.click_through_url ?? "",
    placement: campaign.placement,
    status: campaign.status,
    bid_amount: String(campaign.bid_amount ?? 0),
    skip_offset_seconds: campaign.skip_offset_seconds != null ? String(campaign.skip_offset_seconds) : "",
    starts_at: toDateTimeLocal(campaign.starts_at),
    ends_at: toDateTimeLocal(campaign.ends_at),
    is_active: campaign.is_active,
    creatives: campaign.creatives.map((creative) => ({
      name: creative.name,
      media_url: creative.media_url,
      mime_type: creative.mime_type ?? "video/mp4",
      duration_seconds: creative.duration_seconds != null ? String(creative.duration_seconds) : "",
      width: creative.width != null ? String(creative.width) : "",
      height: creative.height != null ? String(creative.height) : "",
      is_active: creative.is_active,
    })),
    targeting_rules: campaign.targeting_rules.map((rule) => ({
      country_code: rule.country_code ?? "",
      allowed_group: rule.allowed_group ?? "",
      content_id: rule.content_id != null ? String(rule.content_id) : "",
      is_include_rule: rule.is_include_rule,
    })),
  };
}

export function PlaybackOps() {
  const { can } = useAdmin();
  const canRevokeTokens = can("playback.revoke_tokens");
  const canManageAdvertising = can("advertising.manage");
  const [activeTab, setActiveTab] = React.useState("sessions");
  const [ops, setOps] = React.useState<PlaybackOpsResponse>(EMPTY_OPS);
  const [campaignsData, setCampaignsData] = React.useState<AdCampaignsResponse>(EMPTY_CAMPAIGNS);
  const [selectedCampaignId, setSelectedCampaignId] = React.useState<number | "new" | null>(null);
  const [campaignDraft, setCampaignDraft] = React.useState<CampaignDraft>(createEmptyDraft(EMPTY_CAMPAIGNS.options));
  const [isLoading, setIsLoading] = React.useState(false);
  const [isSavingCampaign, setIsSavingCampaign] = React.useState(false);
  const [revokingSessionId, setRevokingSessionId] = React.useState<number | null>(null);

  const loadData = React.useCallback(async () => {
    setIsLoading(true);

    try {
      const [opsResponse, campaignsResponse] = await Promise.all([
        adminApi.getPlaybackOps(),
        adminApi.getAdCampaigns(),
      ]);

      setOps(opsResponse);
      setCampaignsData(campaignsResponse);
      setSelectedCampaignId((current) => current ?? campaignsResponse.items[0]?.id ?? "new");
    } catch {
      setOps(EMPTY_OPS);
      setCampaignsData(EMPTY_CAMPAIGNS);
      setSelectedCampaignId("new");
    } finally {
      setIsLoading(false);
    }
  }, []);

  React.useEffect(() => {
    void loadData();
  }, [loadData]);

  React.useEffect(() => {
    if (selectedCampaignId === "new" || selectedCampaignId === null) {
      setCampaignDraft(createEmptyDraft(campaignsData.options));
      return;
    }

    const selectedCampaign = campaignsData.items.find((item) => item.id === selectedCampaignId);
    if (selectedCampaign) {
      setCampaignDraft(mapCampaignToDraft(selectedCampaign));
    }
  }, [campaignsData, selectedCampaignId]);

  const totalImpressions = React.useMemo(
    () => campaignsData.items.reduce((sum, campaign) => sum + campaign.stats.impressions, 0),
    [campaignsData.items],
  );

  const activeCampaigns = React.useMemo(
    () => campaignsData.items.filter((campaign) => campaign.is_active).length,
    [campaignsData.items],
  );

  const handleRevoke = async (sessionId: number) => {
    setRevokingSessionId(sessionId);

    try {
      await adminApi.revokePlaybackSession(sessionId);
      await loadData();
    } finally {
      setRevokingSessionId(null);
    }
  };

  const updateDraft = <K extends keyof CampaignDraft>(key: K, value: CampaignDraft[K]) => {
    setCampaignDraft((current) => ({ ...current, [key]: value }));
  };

  const handleSaveCampaign = async () => {
    setIsSavingCampaign(true);

    try {
      const payload = {
        name: campaignDraft.name,
        company_name: campaignDraft.company_name || null,
        vast_tag_url: campaignDraft.vast_tag_url || null,
        click_through_url: campaignDraft.click_through_url || null,
        placement: campaignDraft.placement,
        status: campaignDraft.status,
        bid_amount: campaignDraft.bid_amount ? Number(campaignDraft.bid_amount) : 0,
        skip_offset_seconds: campaignDraft.skip_offset_seconds ? Number(campaignDraft.skip_offset_seconds) : null,
        starts_at: campaignDraft.starts_at || null,
        ends_at: campaignDraft.ends_at || null,
        is_active: campaignDraft.is_active,
        creatives: campaignDraft.creatives
          .filter((creative) => creative.name.trim() && creative.media_url.trim())
          .map((creative) => ({
            name: creative.name,
            media_url: creative.media_url,
            mime_type: creative.mime_type || "video/mp4",
            duration_seconds: creative.duration_seconds ? Number(creative.duration_seconds) : null,
            width: creative.width ? Number(creative.width) : null,
            height: creative.height ? Number(creative.height) : null,
            is_active: creative.is_active,
          })),
        targeting_rules: campaignDraft.targeting_rules
          .filter((rule) => rule.country_code || rule.allowed_group || rule.content_id)
          .map((rule) => ({
            country_code: rule.country_code || null,
            allowed_group: rule.allowed_group || null,
            content_id: rule.content_id ? Number(rule.content_id) : null,
            is_include_rule: rule.is_include_rule,
          })),
      };

      const response = campaignDraft.id
        ? await adminApi.updateAdCampaign(campaignDraft.id, payload)
        : await adminApi.createAdCampaign(payload);

      await loadData();
      setSelectedCampaignId(response.campaign.id);
    } finally {
      setIsSavingCampaign(false);
    }
  };

  const handleDeleteCampaign = async () => {
    if (!campaignDraft.id) {
      setSelectedCampaignId("new");
      setCampaignDraft(createEmptyDraft(campaignsData.options));
      return;
    }

    setIsSavingCampaign(true);

    try {
      await adminApi.deleteAdCampaign(campaignDraft.id);
      await loadData();
      setSelectedCampaignId("new");
    } finally {
      setIsSavingCampaign(false);
    }
  };

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
        <div className="page-header">
          <h1 className="page-title">Operațiuni playback</h1>
          <p className="page-description">
            Monitorizare sesiuni live, control tokenuri și administrare campanii VAST fără să schimbăm fluxul actual al platformei.
          </p>
        </div>

        <Tabs
          tabs={[
            { id: "sessions", label: "Sesiuni", count: ops.sessions.length },
            { id: "advertising", label: "Advertising", count: campaignsData.items.length },
          ]}
          activeTab={activeTab}
          onChange={setActiveTab}
        />
      </div>

      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <StatsCard
          title="Streamuri active"
          value={ops.stats.active_streams}
          icon={ActivityIcon}
          trendLabel={`${ops.stats.completed_today} închise azi`}
          colorClass="bg-muted"
        />
        <StatsCard
          title="Watch time urmărit"
          value={formatDuration(ops.stats.total_watch_time_seconds)}
          icon={PlayCircleIcon}
          trendLabel={`${ops.sessions.length} sesiuni încărcate`}
          colorClass="bg-muted"
        />
        <StatsCard
          title="Campanii active"
          value={activeCampaigns}
          icon={MegaphoneIcon}
          trendLabel={`${campaignsData.items.length} totale`}
          colorClass="bg-muted"
        />
        <StatsCard
          title="Impresii agregate"
          value={totalImpressions}
          icon={ShieldIcon}
          trendLabel="din analytics ads"
          colorClass="bg-muted"
        />
      </div>

      {activeTab === "sessions" ? (
        <Card>
          <CardHeader>
            <CardTitle>Sesiuni playback</CardTitle>
            <CardDescription>Flux live din `playback_sessions`, cu posibilitate de revocare imediată.</CardDescription>
          </CardHeader>
          <CardContent className="p-0">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Utilizator</TableHead>
                  <TableHead>Conținut</TableHead>
                  <TableHead>Calitate</TableHead>
                  <TableHead>Țară</TableHead>
                  <TableHead>Dispozitiv</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead>Watch time</TableHead>
                  <TableHead>Pornit</TableHead>
                  <TableHead className="text-right">Acțiuni</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {ops.sessions.length > 0 ? (
                  ops.sessions.map((session) => (
                    <TableRow key={session.id}>
                      <TableCell>
                        <div className="space-y-1">
                          <p className="font-medium">{session.user_name ?? "Utilizator necunoscut"}</p>
                          <p className="text-xs text-muted-foreground">{session.user_email ?? "Fără email"}</p>
                        </div>
                      </TableCell>
                      <TableCell>{session.content_title ?? "Conținut lipsă"}</TableCell>
                      <TableCell>{session.quality ?? "N/A"}</TableCell>
                      <TableCell>{session.country_code ?? "N/A"}</TableCell>
                      <TableCell>{session.device_type ?? "Necunoscut"}</TableCell>
                      <TableCell className="capitalize">{session.status}</TableCell>
                      <TableCell>{formatDuration(session.watch_time_seconds)}</TableCell>
                      <TableCell>{formatDate(session.started_at)}</TableCell>
                      <TableCell className="text-right">
                        {canRevokeTokens ? (
                          <Button
                            variant="outline"
                            size="sm"
                            onClick={() => void handleRevoke(session.id)}
                            disabled={revokingSessionId === session.id}
                          >
                            {revokingSessionId === session.id ? "Se revocă..." : "Revocă"}
                          </Button>
                        ) : (
                          <span className="text-xs text-muted-foreground">Doar vizualizare</span>
                        )}
                      </TableCell>
                    </TableRow>
                  ))
                ) : (
                  <TableRow>
                    <TableCell colSpan={9} className="h-32 text-center text-muted-foreground">
                      {isLoading ? "Se încarcă sesiunile..." : "Nu există încă sesiuni playback înregistrate."}
                    </TableCell>
                  </TableRow>
                )}
              </TableBody>
            </Table>
          </CardContent>
        </Card>
      ) : (
        <div className="grid gap-6 xl:grid-cols-[340px_minmax(0,1fr)]">
          <Card>
            <CardHeader className="flex flex-row items-center justify-between gap-3">
              <div>
                <CardTitle>Campanii ads</CardTitle>
                <CardDescription>Campaniile disponibile pentru pre-roll, mid-roll și post-roll.</CardDescription>
              </div>
              <Button
                variant="outline"
                onClick={() => setSelectedCampaignId("new")}
                disabled={!canManageAdvertising}
              >
                Nouă
              </Button>
            </CardHeader>
            <CardContent className="space-y-3">
              {campaignsData.items.length > 0 ? (
                campaignsData.items.map((campaign) => {
                  const isActive = selectedCampaignId === campaign.id;
                  return (
                    <button
                      key={campaign.id}
                      type="button"
                      onClick={() => setSelectedCampaignId(campaign.id)}
                      className={`w-full rounded-lg border p-4 text-left transition-colors ${
                        isActive ? "border-primary bg-primary/5" : "bg-background hover:bg-muted/40"
                      }`}
                    >
                      <div className="flex items-start justify-between gap-3">
                        <div>
                          <p className="font-medium">{campaign.name}</p>
                          <p className="text-sm text-muted-foreground">{campaign.company_name || "Fără companie"}</p>
                        </div>
                        <span className="rounded-full border px-2 py-1 text-[11px] uppercase">{campaign.status}</span>
                      </div>
                      <div className="mt-3 grid gap-2 text-sm text-muted-foreground">
                        <div className="flex justify-between">
                          <span>Placement</span>
                          <span className="font-medium text-foreground">{campaign.placement}</span>
                        </div>
                        <div className="flex justify-between">
                          <span>Impresii</span>
                          <span className="font-medium text-foreground">{campaign.stats.impressions}</span>
                        </div>
                        <div className="flex justify-between">
                          <span>Clicks</span>
                          <span className="font-medium text-foreground">{campaign.stats.clicks}</span>
                        </div>
                      </div>
                    </button>
                  );
                })
              ) : (
                <div className="flex h-40 items-center justify-center rounded-lg border bg-background text-sm text-muted-foreground">
                  {isLoading ? "Se încarcă campaniile..." : "Nu există încă reclame configurate."}
                </div>
              )}
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>{campaignDraft.id ? "Editează campania" : "Campanie nouă"}</CardTitle>
              <CardDescription>Configurare completă pentru VAST, creatives și reguli de targeting.</CardDescription>
            </CardHeader>
            <CardContent className="space-y-6">
              <div className="grid gap-4 md:grid-cols-2">
                <label className="space-y-2 text-sm">
                  <span className="text-muted-foreground">Nume campanie</span>
                  <Input value={campaignDraft.name} onChange={(event) => updateDraft("name", event.target.value)} disabled={!canManageAdvertising} />
                </label>
                <label className="space-y-2 text-sm">
                  <span className="text-muted-foreground">Companie</span>
                  <Input value={campaignDraft.company_name} onChange={(event) => updateDraft("company_name", event.target.value)} disabled={!canManageAdvertising} />
                </label>
                <label className="space-y-2 text-sm">
                  <span className="text-muted-foreground">Placement</span>
                  <select
                    className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                    value={campaignDraft.placement}
                    onChange={(event) => updateDraft("placement", event.target.value)}
                    disabled={!canManageAdvertising}
                  >
                    {campaignsData.options.placements.map((placement) => (
                      <option key={placement} value={placement}>
                        {placement}
                      </option>
                    ))}
                  </select>
                </label>
                <label className="space-y-2 text-sm">
                  <span className="text-muted-foreground">Status</span>
                  <select
                    className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                    value={campaignDraft.status}
                    onChange={(event) => updateDraft("status", event.target.value)}
                    disabled={!canManageAdvertising}
                  >
                    {campaignsData.options.statuses.map((status) => (
                      <option key={status} value={status}>
                        {status}
                      </option>
                    ))}
                  </select>
                </label>
                <label className="space-y-2 text-sm">
                  <span className="text-muted-foreground">Bid</span>
                  <Input
                    type="number"
                    step="0.01"
                    value={campaignDraft.bid_amount}
                    onChange={(event) => updateDraft("bid_amount", event.target.value)}
                    disabled={!canManageAdvertising}
                  />
                </label>
                <label className="space-y-2 text-sm">
                  <span className="text-muted-foreground">Skip offset (sec)</span>
                  <Input
                    type="number"
                    min="0"
                    value={campaignDraft.skip_offset_seconds}
                    onChange={(event) => updateDraft("skip_offset_seconds", event.target.value)}
                    disabled={!canManageAdvertising}
                  />
                </label>
                <label className="space-y-2 text-sm">
                  <span className="text-muted-foreground">Start</span>
                  <Input
                    type="datetime-local"
                    value={campaignDraft.starts_at}
                    onChange={(event) => updateDraft("starts_at", event.target.value)}
                    disabled={!canManageAdvertising}
                  />
                </label>
                <label className="space-y-2 text-sm">
                  <span className="text-muted-foreground">Final</span>
                  <Input
                    type="datetime-local"
                    value={campaignDraft.ends_at}
                    onChange={(event) => updateDraft("ends_at", event.target.value)}
                    disabled={!canManageAdvertising}
                  />
                </label>
              </div>

              <div className="grid gap-4 md:grid-cols-[minmax(0,1fr)_220px]">
                <label className="space-y-2 text-sm">
                  <span className="text-muted-foreground">VAST tag URL</span>
                  <Input value={campaignDraft.vast_tag_url} onChange={(event) => updateDraft("vast_tag_url", event.target.value)} disabled={!canManageAdvertising} />
                </label>
                <div className="flex items-center justify-between rounded-lg border px-4 py-3">
                  <div>
                    <p className="text-sm font-medium">Campanie activă</p>
                    <p className="text-xs text-muted-foreground">Controlează rapid eligibilitatea campaniei.</p>
                  </div>
                  <Switch checked={campaignDraft.is_active} onCheckedChange={(checked) => updateDraft("is_active", checked)} disabled={!canManageAdvertising} />
                </div>
              </div>

              <label className="space-y-2 text-sm">
                <span className="text-muted-foreground">Click through URL</span>
                <Input
                  value={campaignDraft.click_through_url}
                  onChange={(event) => updateDraft("click_through_url", event.target.value)}
                  disabled={!canManageAdvertising}
                />
              </label>

              <div className="space-y-4">
                <div className="flex items-center justify-between">
                  <div>
                    <h3 className="font-medium">Creatives</h3>
                    <p className="text-sm text-muted-foreground">Fișierele media care vor fi servite prin VAST.</p>
                  </div>
                  <Button
                    variant="outline"
                    disabled={!canManageAdvertising}
                    onClick={() =>
                      updateDraft("creatives", [
                        ...campaignDraft.creatives,
                        {
                          name: "",
                          media_url: "",
                          mime_type: "video/mp4",
                          duration_seconds: "",
                          width: "",
                          height: "",
                          is_active: true,
                        },
                      ])
                    }
                  >
                    Adaugă creative
                  </Button>
                </div>

                <div className="space-y-3">
                  {campaignDraft.creatives.map((creative, index) => (
                    <div key={`creative-${index}`} className="rounded-lg border p-4">
                      <div className="grid gap-4 md:grid-cols-2">
                        <label className="space-y-2 text-sm">
                          <span className="text-muted-foreground">Nume</span>
                          <Input
                            value={creative.name}
                            disabled={!canManageAdvertising}
                            onChange={(event) =>
                              updateDraft(
                                "creatives",
                                campaignDraft.creatives.map((item, itemIndex) =>
                                  itemIndex === index ? { ...item, name: event.target.value } : item,
                                ),
                              )
                            }
                          />
                        </label>
                        <label className="space-y-2 text-sm">
                          <span className="text-muted-foreground">Media URL</span>
                          <Input
                            value={creative.media_url}
                            disabled={!canManageAdvertising}
                            onChange={(event) =>
                              updateDraft(
                                "creatives",
                                campaignDraft.creatives.map((item, itemIndex) =>
                                  itemIndex === index ? { ...item, media_url: event.target.value } : item,
                                ),
                              )
                            }
                          />
                        </label>
                        <label className="space-y-2 text-sm">
                          <span className="text-muted-foreground">Mime type</span>
                          <Input
                            value={creative.mime_type}
                            disabled={!canManageAdvertising}
                            onChange={(event) =>
                              updateDraft(
                                "creatives",
                                campaignDraft.creatives.map((item, itemIndex) =>
                                  itemIndex === index ? { ...item, mime_type: event.target.value } : item,
                                ),
                              )
                            }
                          />
                        </label>
                        <label className="space-y-2 text-sm">
                          <span className="text-muted-foreground">Durată (sec)</span>
                          <Input
                            type="number"
                            min="1"
                            value={creative.duration_seconds}
                            disabled={!canManageAdvertising}
                            onChange={(event) =>
                              updateDraft(
                                "creatives",
                                campaignDraft.creatives.map((item, itemIndex) =>
                                  itemIndex === index ? { ...item, duration_seconds: event.target.value } : item,
                                ),
                              )
                            }
                          />
                        </label>
                        <label className="space-y-2 text-sm">
                          <span className="text-muted-foreground">Lățime</span>
                          <Input
                            type="number"
                            min="1"
                            value={creative.width}
                            disabled={!canManageAdvertising}
                            onChange={(event) =>
                              updateDraft(
                                "creatives",
                                campaignDraft.creatives.map((item, itemIndex) =>
                                  itemIndex === index ? { ...item, width: event.target.value } : item,
                                ),
                              )
                            }
                          />
                        </label>
                        <label className="space-y-2 text-sm">
                          <span className="text-muted-foreground">Înălțime</span>
                          <Input
                            type="number"
                            min="1"
                            value={creative.height}
                            disabled={!canManageAdvertising}
                            onChange={(event) =>
                              updateDraft(
                                "creatives",
                                campaignDraft.creatives.map((item, itemIndex) =>
                                  itemIndex === index ? { ...item, height: event.target.value } : item,
                                ),
                              )
                            }
                          />
                        </label>
                      </div>

                      <div className="mt-4 flex items-center justify-between">
                        <div className="flex items-center gap-3">
                          <Switch
                            checked={creative.is_active}
                            disabled={!canManageAdvertising}
                            onCheckedChange={(checked) =>
                              updateDraft(
                                "creatives",
                                campaignDraft.creatives.map((item, itemIndex) =>
                                  itemIndex === index ? { ...item, is_active: checked } : item,
                                ),
                              )
                            }
                          />
                          <span className="text-sm text-muted-foreground">Creative activ</span>
                        </div>
                        <Button
                          variant="ghost"
                          size="icon"
                          disabled={!canManageAdvertising}
                          onClick={() =>
                            updateDraft(
                              "creatives",
                              campaignDraft.creatives.filter((_, itemIndex) => itemIndex !== index),
                            )
                          }
                        >
                          <Trash2Icon className="h-4 w-4" />
                        </Button>
                      </div>
                    </div>
                  ))}
                </div>
              </div>

              <div className="space-y-4">
                <div className="flex items-center justify-between">
                  <div>
                    <h3 className="font-medium">Targeting rules</h3>
                    <p className="text-sm text-muted-foreground">Filtre pe țări, grupuri și titluri specifice.</p>
                  </div>
                  <Button
                    variant="outline"
                    disabled={!canManageAdvertising}
                    onClick={() =>
                      updateDraft("targeting_rules", [
                        ...campaignDraft.targeting_rules,
                        {
                          country_code: "",
                          allowed_group: "",
                          content_id: "",
                          is_include_rule: true,
                        },
                      ])
                    }
                  >
                    Adaugă regulă
                  </Button>
                </div>

                <div className="space-y-3">
                  {campaignDraft.targeting_rules.map((rule, index) => (
                    <div key={`rule-${index}`} className="rounded-lg border p-4">
                      <div className="grid gap-4 md:grid-cols-3">
                        <label className="space-y-2 text-sm">
                          <span className="text-muted-foreground">Țară</span>
                          <Input
                            placeholder="MD"
                            value={rule.country_code}
                            disabled={!canManageAdvertising}
                            onChange={(event) =>
                              updateDraft(
                                "targeting_rules",
                                campaignDraft.targeting_rules.map((item, itemIndex) =>
                                  itemIndex === index ? { ...item, country_code: event.target.value.toUpperCase() } : item,
                                ),
                              )
                            }
                          />
                        </label>
                        <label className="space-y-2 text-sm">
                          <span className="text-muted-foreground">Allowed group</span>
                          <select
                            className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                            value={rule.allowed_group}
                            disabled={!canManageAdvertising}
                            onChange={(event) =>
                              updateDraft(
                                "targeting_rules",
                                campaignDraft.targeting_rules.map((item, itemIndex) =>
                                  itemIndex === index ? { ...item, allowed_group: event.target.value } : item,
                                ),
                              )
                            }
                          >
                            <option value="">Toate</option>
                            {campaignsData.options.allowed_groups.map((group) => (
                              <option key={group} value={group}>
                                {group}
                              </option>
                            ))}
                          </select>
                        </label>
                        <label className="space-y-2 text-sm">
                          <span className="text-muted-foreground">Titlu</span>
                          <select
                            className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                            value={rule.content_id}
                            disabled={!canManageAdvertising}
                            onChange={(event) =>
                              updateDraft(
                                "targeting_rules",
                                campaignDraft.targeting_rules.map((item, itemIndex) =>
                                  itemIndex === index ? { ...item, content_id: event.target.value } : item,
                                ),
                              )
                            }
                          >
                            <option value="">Toate titlurile</option>
                            {campaignsData.options.contents.map((content) => (
                              <option key={content.id} value={content.id}>
                                {content.title}
                              </option>
                            ))}
                          </select>
                        </label>
                      </div>

                      <div className="mt-4 flex items-center justify-between">
                        <div className="flex items-center gap-3">
                          <Switch
                            checked={rule.is_include_rule}
                            disabled={!canManageAdvertising}
                            onCheckedChange={(checked) =>
                              updateDraft(
                                "targeting_rules",
                                campaignDraft.targeting_rules.map((item, itemIndex) =>
                                  itemIndex === index ? { ...item, is_include_rule: checked } : item,
                                ),
                              )
                            }
                          />
                          <span className="text-sm text-muted-foreground">Regulă de includere</span>
                        </div>
                        <Button
                          variant="ghost"
                          size="icon"
                          disabled={!canManageAdvertising}
                          onClick={() =>
                            updateDraft(
                              "targeting_rules",
                              campaignDraft.targeting_rules.filter((_, itemIndex) => itemIndex !== index),
                            )
                          }
                        >
                          <Trash2Icon className="h-4 w-4" />
                        </Button>
                      </div>
                    </div>
                  ))}
                </div>
              </div>

              <div className="flex flex-wrap gap-3">
                <Button onClick={() => void handleSaveCampaign()} disabled={!canManageAdvertising || isSavingCampaign || !campaignDraft.name.trim()}>
                  {isSavingCampaign ? "Se salvează..." : campaignDraft.id ? "Salvează modificările" : "Creează campania"}
                </Button>
                <Button variant="outline" onClick={() => setSelectedCampaignId("new")} disabled={!canManageAdvertising || isSavingCampaign}>
                  Draft nou
                </Button>
                <Button variant="destructive" onClick={() => void handleDeleteCampaign()} disabled={!canManageAdvertising || isSavingCampaign}>
                  {campaignDraft.id ? "Șterge campania" : "Resetează formularul"}
                </Button>
              </div>
            </CardContent>
          </Card>
        </div>
      )}
    </div>
  );
}
