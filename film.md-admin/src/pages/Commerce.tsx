import React, { useEffect, useMemo, useState } from "react";
import {
  CreditCardIcon,
  DollarSignIcon,
  EditIcon,
  PlusIcon,
  ShoppingCartIcon,
  TrashIcon,
} from "lucide-react";
import { Badge } from "../components/shared/Badge";
import { DataTable } from "../components/shared/DataTable";
import { FormField } from "../components/shared/FormField";
import { Modal } from "../components/shared/Modal";
import { StatsCard } from "../components/shared/StatsCard";
import { Button } from "../components/ui/button";
import { useAdmin } from "../hooks/useAdmin";
import { adminApi, ApiRequestError } from "../lib/api";
import { AdminOffer, AdminOfferType, OfferIndexResponse, OfferPayload } from "../types";

type OfferFormState = {
  content_id: number | "";
  name: string;
  offer_type: AdminOfferType;
  quality: string;
  currency: string;
  price_amount: number | "";
  rental_days: number | "";
  is_active: boolean;
  starts_at: string;
  ends_at: string;
  sort_order: number | "";
};

type OfferRow = AdminOffer & {
  search_index: string;
};

const EMPTY_FILTERS: OfferIndexResponse["filters"] = {
  types: [
    { value: "rental", label: "Rental" },
    { value: "lifetime", label: "Forever" },
  ],
  qualities: [
    { value: "SD", label: "SD" },
    { value: "HD", label: "HD" },
    { value: "Full HD", label: "Full HD" },
    { value: "4K", label: "4K" },
  ],
  contents: [],
};

function createEmptyForm(filters: OfferIndexResponse["filters"]): OfferFormState {
  return {
    content_id: filters.contents[0] ? Number(filters.contents[0].value) : "",
    name: "",
    offer_type: "rental",
    quality: filters.qualities[0]?.value ?? "HD",
    currency: "USD",
    price_amount: "",
    rental_days: 2,
    is_active: true,
    starts_at: "",
    ends_at: "",
    sort_order: 0,
  };
}

function mapOfferToForm(offer: AdminOffer): OfferFormState {
  return {
    content_id: offer.content_id,
    name: offer.name,
    offer_type: offer.offer_type,
    quality: offer.quality,
    currency: offer.currency,
    price_amount: offer.price_amount,
    rental_days: offer.rental_days ?? "",
    is_active: offer.is_active,
    starts_at: offer.starts_at ? offer.starts_at.slice(0, 10) : "",
    ends_at: offer.ends_at ? offer.ends_at.slice(0, 10) : "",
    sort_order: offer.sort_order,
  };
}

function formatMoney(amount: number, currency: string) {
  return new Intl.NumberFormat("en-US", {
    style: "currency",
    currency: currency || "USD",
  }).format(amount);
}

function statusVariant(status: AdminOffer["availability_status"]) {
  switch (status) {
    case "active":
      return "active";
    case "scheduled":
      return "scheduled";
    case "expired":
      return "expired";
    default:
      return "inactive";
  }
}

export function Commerce() {
  const { can } = useAdmin();
  const [offers, setOffers] = useState<AdminOffer[]>([]);
  const [stats, setStats] = useState<OfferIndexResponse["stats"]>({
    total_offers: 0,
    active_offers: 0,
    rental_offers: 0,
    lifetime_offers: 0,
  });
  const [filters, setFilters] = useState<OfferIndexResponse["filters"]>(EMPTY_FILTERS);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [editingOffer, setEditingOffer] = useState<AdminOffer | null>(null);
  const [formData, setFormData] = useState<OfferFormState>(createEmptyForm(EMPTY_FILTERS));
  const [validationErrors, setValidationErrors] = useState<Record<string, string[]>>({});
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [typeFilter, setTypeFilter] = useState<string>("all");
  const [qualityFilter, setQualityFilter] = useState<string>("all");
  const [contentFilter, setContentFilter] = useState<string>("all");

  async function loadData() {
    setIsLoading(true);
    setError(null);

    try {
      const response = await adminApi.getOffers();
      setOffers(response.items);
      setStats(response.stats);
      setFilters(response.filters);
      setFormData((current) =>
        current.content_id === ""
          ? createEmptyForm(response.filters)
          : current,
      );
    } catch (loadError) {
      setError(loadError instanceof Error ? loadError.message : "Nu am putut încărca ofertele.");
    } finally {
      setIsLoading(false);
    }
  }

  useEffect(() => {
    void loadData();
  }, []);

  const rows = useMemo<OfferRow[]>(
    () =>
      offers
        .filter((offer) => {
          const matchesType = typeFilter === "all" ? true : offer.offer_type === typeFilter;
          const matchesQuality = qualityFilter === "all" ? true : offer.quality === qualityFilter;
          const matchesContent = contentFilter === "all" ? true : String(offer.content_id) === contentFilter;

          return matchesType && matchesQuality && matchesContent;
        })
        .map((offer) => ({
          ...offer,
          search_index: [
            offer.content_title ?? "",
            offer.name,
            offer.offer_type,
            offer.quality,
            offer.access_label,
            offer.currency,
          ].join(" "),
        })),
    [contentFilter, offers, qualityFilter, typeFilter],
  );

  function handleOpenModal(offer?: AdminOffer) {
    setValidationErrors({});
    setError(null);
    setSuccessMessage(null);
    setEditingOffer(offer ?? null);
    setFormData(offer ? mapOfferToForm(offer) : createEmptyForm(filters));
    setIsModalOpen(true);
  }

  async function handleDelete(offer: AdminOffer) {
    const confirmed = window.confirm(`Ștergi oferta "${offer.name}" pentru "${offer.content_title}"?`);

    if (!confirmed) {
      return;
    }

    setError(null);
    setSuccessMessage(null);

    try {
      await adminApi.deleteOffer(offer.id);
      setSuccessMessage("Oferta a fost ștearsă.");
      await loadData();
    } catch (deleteError) {
      setError(deleteError instanceof Error ? deleteError.message : "Nu am putut șterge oferta.");
    }
  }

  function getFieldError(field: string) {
    return validationErrors[field]?.[0];
  }

  async function handleSave() {
    if (formData.content_id === "") {
      setValidationErrors({ content_id: ["Selectează filmul sau serialul."] });
      return;
    }

    setIsSubmitting(true);
    setValidationErrors({});
    setError(null);
    setSuccessMessage(null);

    const payload: OfferPayload = {
      content_id: Number(formData.content_id),
      name: formData.name.trim() || undefined,
      offer_type: formData.offer_type,
      quality: formData.quality,
      currency: formData.currency,
      price_amount: Number(formData.price_amount || 0),
      rental_days: formData.offer_type === "rental" ? Number(formData.rental_days || 0) : null,
      is_active: formData.is_active,
      starts_at: formData.starts_at || null,
      ends_at: formData.ends_at || null,
      sort_order: Number(formData.sort_order || 0),
    };

    try {
      if (editingOffer) {
        await adminApi.updateOffer(editingOffer.id, payload);
        setSuccessMessage("Oferta a fost actualizată.");
      } else {
        await adminApi.createOffer(payload);
        setSuccessMessage("Oferta a fost creată.");
      }

      setIsModalOpen(false);
      await loadData();
    } catch (saveError) {
      if (saveError instanceof Error) {
        setError(saveError.message);
      }

      if ((saveError as ApiRequestError).errors) {
        setValidationErrors((saveError as ApiRequestError).errors ?? {});
      }
    } finally {
      setIsSubmitting(false);
    }
  }

  const columns = [
    {
      key: "content",
      header: "Content",
      render: (item: OfferRow) => (
        <div className="flex items-center gap-3">
          <img
            src={item.poster_url ?? "https://placehold.co/88x128?text=Film"}
            alt={item.content_title ?? item.name}
            className="h-16 w-11 rounded-md border object-cover"
          />
          <div className="space-y-1">
            <div className="font-medium">{item.content_title ?? "Unknown title"}</div>
            <div className="text-xs text-muted-foreground">{item.name}</div>
            <div className="text-xs uppercase tracking-wide text-muted-foreground">
              {(item.content_type ?? "movie")} • {item.content_slug ?? "no-slug"}
            </div>
          </div>
        </div>
      ),
    },
    {
      key: "access",
      header: "Access",
      render: (item: OfferRow) => (
        <div className="space-y-2">
          <div className="flex flex-wrap gap-2">
            <Badge variant={item.offer_type === "lifetime" ? "featured" : "ready"}>
              {item.offer_type_label}
            </Badge>
            <Badge variant="paid">{item.access_label}</Badge>
          </div>
          <div className="text-xs text-muted-foreground">{item.quality}</div>
        </div>
      ),
    },
    {
      key: "price",
      header: "Price",
      render: (item: OfferRow) => (
        <div className="text-sm font-medium">{formatMoney(item.price_amount, item.currency)}</div>
      ),
    },
    {
      key: "window",
      header: "Availability Window",
      render: (item: OfferRow) => (
        <div className="space-y-1 text-sm text-muted-foreground">
          <div>{item.starts_at ? item.starts_at.slice(0, 10) : "Starts now"}</div>
          <div>{item.ends_at ? item.ends_at.slice(0, 10) : "No end date"}</div>
        </div>
      ),
    },
    {
      key: "status",
      header: "Status",
      render: (item: OfferRow) => (
        <Badge variant={statusVariant(item.availability_status)}>{item.availability_status}</Badge>
      ),
    },
    {
      key: "actions",
      header: "",
      render: (item: OfferRow) => (
        <div className="flex items-center justify-end gap-2">
          {can("commerce.edit_offers") ? (
            <Button
              variant="ghost"
              size="icon"
              onClick={(event) => {
                event.stopPropagation();
                handleOpenModal(item);
              }}
            >
              <EditIcon className="h-4 w-4" />
            </Button>
          ) : null}

          {can("commerce.edit_offers") ? (
            <Button
              variant="ghost"
              size="icon"
              onClick={(event) => {
                event.stopPropagation();
                void handleDelete(item);
              }}
            >
              <TrashIcon className="h-4 w-4" />
            </Button>
          ) : null}
        </div>
      ),
    },
  ];

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
        <div className="page-header">
          <h1 className="page-title">Offers & Pricing</h1>
          <p className="page-description">
            Configurează combinațiile reale de access: pe zile sau forever, cu preț separat pe fiecare calitate.
          </p>
        </div>

        {can("commerce.create_offers") ? (
          <Button onClick={() => handleOpenModal()}>
            <PlusIcon className="h-4 w-4" />
            Add offer
          </Button>
        ) : null}
      </div>

      {error ? (
        <div className="rounded-md border border-destructive/20 bg-destructive/5 px-4 py-3 text-sm text-destructive">
          {error}
        </div>
      ) : null}

      {successMessage ? (
        <div className="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
          {successMessage}
        </div>
      ) : null}

      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <StatsCard title="Total Offers" value={stats.total_offers} icon={ShoppingCartIcon} />
        <StatsCard
          title="Live Offers"
          value={stats.active_offers}
          icon={DollarSignIcon}
          colorClass="bg-emerald-50 text-emerald-700"
        />
        <StatsCard
          title="Rental Plans"
          value={stats.rental_offers}
          icon={CreditCardIcon}
          colorClass="bg-amber-50 text-amber-700"
        />
        <StatsCard
          title="Forever Plans"
          value={stats.lifetime_offers}
          icon={CreditCardIcon}
          colorClass="bg-sky-50 text-sky-700"
        />
      </div>

      <div className="grid gap-4 rounded-xl border p-4 md:grid-cols-3">
        <FormField
          label="Content"
          type="select"
          value={contentFilter}
          onChange={(event) => setContentFilter(event.target.value)}
          options={[
            { label: "All titles", value: "all" },
            ...filters.contents,
          ]}
        />
        <FormField
          label="Access Type"
          type="select"
          value={typeFilter}
          onChange={(event) => setTypeFilter(event.target.value)}
          options={[
            { label: "All access types", value: "all" },
            ...filters.types,
          ]}
        />
        <FormField
          label="Quality"
          type="select"
          value={qualityFilter}
          onChange={(event) => setQualityFilter(event.target.value)}
          options={[
            { label: "All qualities", value: "all" },
            ...filters.qualities,
          ]}
        />
      </div>

      {isLoading ? (
        <div className="rounded-xl border p-10 text-center text-sm text-muted-foreground">
          Se încarcă ofertele...
        </div>
      ) : (
        <DataTable
          data={rows}
          columns={columns}
          keyExtractor={(item) => String(item.id)}
          searchPlaceholder="Search by title, access type or quality..."
        />
      )}

      <Modal
        isOpen={isModalOpen}
        onClose={() => setIsModalOpen(false)}
        title={editingOffer ? "Edit offer" : "Create offer"}
        size="lg"
        footer={
          <>
            <Button variant="outline" onClick={() => setIsModalOpen(false)}>
              Cancel
            </Button>
            <Button onClick={() => void handleSave()} disabled={isSubmitting}>
              {isSubmitting ? "Saving..." : editingOffer ? "Save changes" : "Create offer"}
            </Button>
          </>
        }
      >
        <div className="space-y-5">
          <FormField
            label="Title"
            type="select"
            value={String(formData.content_id)}
            onChange={(event) =>
              setFormData((current) => ({
                ...current,
                content_id: event.target.value === "" ? "" : Number(event.target.value),
              }))
            }
            error={getFieldError("content_id")}
            options={filters.contents}
          />

          <FormField
            label="Offer name"
            value={formData.name}
            onChange={(event) =>
              setFormData((current) => ({
                ...current,
                name: event.target.value,
              }))
            }
            helperText="Opțional. Dacă lași gol, backend-ul generează automat un nume clar, de exemplu “Forever Full HD”."
            error={getFieldError("name")}
          />

          <div className="grid gap-4 md:grid-cols-2">
            <FormField
              label="Access type"
              type="select"
              value={formData.offer_type}
              onChange={(event) =>
                setFormData((current) => ({
                  ...current,
                  offer_type: event.target.value as AdminOfferType,
                }))
              }
              error={getFieldError("offer_type")}
              options={filters.types}
            />

            <FormField
              label="Quality"
              type="select"
              value={formData.quality}
              onChange={(event) =>
                setFormData((current) => ({
                  ...current,
                  quality: event.target.value,
                }))
              }
              error={getFieldError("quality")}
              options={filters.qualities}
            />
          </div>

          <div className="grid gap-4 md:grid-cols-3">
            <FormField
              label="Price"
              type="number"
              value={formData.price_amount}
              onChange={(event) =>
                setFormData((current) => ({
                  ...current,
                  price_amount: event.target.value === "" ? "" : Number(event.target.value),
                }))
              }
              error={getFieldError("price_amount")}
            />

            <FormField
              label="Currency"
              type="select"
              value={formData.currency}
              onChange={(event) =>
                setFormData((current) => ({
                  ...current,
                  currency: event.target.value,
                }))
              }
              options={[
                { label: "USD", value: "USD" },
                { label: "EUR", value: "EUR" },
                { label: "MDL", value: "MDL" },
              ]}
            />

            <FormField
              label="Sort order"
              type="number"
              value={formData.sort_order}
              onChange={(event) =>
                setFormData((current) => ({
                  ...current,
                  sort_order: event.target.value === "" ? "" : Number(event.target.value),
                }))
              }
              error={getFieldError("sort_order")}
            />
          </div>

          {formData.offer_type === "rental" ? (
            <FormField
              label="Access duration (days)"
              type="number"
              value={formData.rental_days}
              onChange={(event) =>
                setFormData((current) => ({
                  ...current,
                  rental_days: event.target.value === "" ? "" : Number(event.target.value),
                }))
              }
              error={getFieldError("rental_days")}
              helperText="Pentru offers de tip rental, numărul de zile este obligatoriu."
            />
          ) : null}

          <div className="grid gap-4 md:grid-cols-2">
            <FormField
              label="Starts at"
              type="date"
              value={formData.starts_at}
              onChange={(event) =>
                setFormData((current) => ({
                  ...current,
                  starts_at: event.target.value,
                }))
              }
              error={getFieldError("starts_at")}
            />
            <FormField
              label="Ends at"
              type="date"
              value={formData.ends_at}
              onChange={(event) =>
                setFormData((current) => ({
                  ...current,
                  ends_at: event.target.value,
                }))
              }
              error={getFieldError("ends_at")}
            />
          </div>

          <FormField
            label="Offer active"
            type="toggle"
            checked={formData.is_active}
            onChange={(event) =>
              setFormData((current) => ({
                ...current,
                is_active: Boolean(event.target.checked),
              }))
            }
            helperText="Dacă e oprită, oferta nu mai apare în storefront chiar dacă există în bază."
          />
        </div>
      </Modal>
    </div>
  );
}
