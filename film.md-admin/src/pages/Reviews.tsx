import { useEffect, useState } from "react";
import { MessageSquareTextIcon } from "lucide-react";
import { Badge } from "../components/shared/Badge";
import { ReviewsTable } from "../components/content/ContentReviewsTab";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "../components/ui/card";
import { adminApi } from "../lib/api";
import type { AdminContentReview, AdminContentReviewsResponse } from "../types";

export function Reviews() {
  const [data, setData] = useState<AdminContentReviewsResponse | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [busyId, setBusyId] = useState<number | null>(null);

  async function load() {
    setIsLoading(true);
    try {
      setData(await adminApi.getReviews());
    } finally {
      setIsLoading(false);
    }
  }

  useEffect(() => {
    void load();
  }, []);

  async function setStatus(review: AdminContentReview, status: "published" | "hidden") {
    setBusyId(review.id);
    try {
      await adminApi.updateReview(review.id, { status });
      await load();
    } finally {
      setBusyId(null);
    }
  }

  async function remove(review: AdminContentReview) {
    if (!confirm("Ștergi acest review?")) {
      return;
    }

    setBusyId(review.id);
    try {
      await adminApi.deleteReview(review.id);
      await load();
    } finally {
      setBusyId(null);
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div className="page-header">
          <h1 className="page-title">Review-uri</h1>
          <p className="page-description">Toate review-urile trimise de utilizatori pentru filme și seriale.</p>
        </div>
        <div className="rounded-md border bg-muted p-2">
          <MessageSquareTextIcon className="h-5 w-5" />
        </div>
      </div>

      <div className="grid gap-4 md:grid-cols-3">
        <StatsCard label="Total" value={data?.stats.total ?? 0} />
        <StatsCard label="Publicate" value={data?.stats.published ?? 0} badge="active" />
        <StatsCard label="Ascunse" value={data?.stats.hidden ?? 0} badge="inactive" />
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Listă review-uri</CardTitle>
          <CardDescription>Poți ascunde, republica sau șterge review-uri.</CardDescription>
        </CardHeader>
        <CardContent className="p-0">
          {isLoading ? (
            <div className="p-10 text-center text-sm text-muted-foreground">Se încarcă review-urile...</div>
          ) : (
            <ReviewsTable reviews={data?.items ?? []} busyId={busyId} onStatus={setStatus} onDelete={remove} />
          )}
        </CardContent>
      </Card>
    </div>
  );
}

function StatsCard({ label, value, badge }: { label: string; value: number; badge?: "active" | "inactive" }) {
  return (
    <Card>
      <CardHeader className="flex flex-row items-start justify-between space-y-0 pb-3">
        <div>
          <CardDescription>{label}</CardDescription>
          <CardTitle className="mt-2">{value.toLocaleString()}</CardTitle>
        </div>
        {badge ? <Badge variant={badge}>{label}</Badge> : null}
      </CardHeader>
    </Card>
  );
}
