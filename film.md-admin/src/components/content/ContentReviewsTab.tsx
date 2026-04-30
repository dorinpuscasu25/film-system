import { useEffect, useState } from "react";
import { EyeIcon, EyeOffIcon, StarIcon, Trash2Icon } from "lucide-react";
import { Badge } from "../shared/Badge";
import { Button } from "../ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "../ui/card";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "../ui/table";
import { adminApi } from "../../lib/api";
import type { AdminContentReview, AdminContentReviewsResponse } from "../../types";

interface ContentReviewsTabProps {
  contentId: number;
}

export function ContentReviewsTab({ contentId }: ContentReviewsTabProps) {
  const [data, setData] = useState<AdminContentReviewsResponse | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [busyId, setBusyId] = useState<number | null>(null);

  async function load() {
    setIsLoading(true);
    try {
      setData(await adminApi.getContentReviews(contentId));
    } finally {
      setIsLoading(false);
    }
  }

  useEffect(() => {
    void load();
  }, [contentId]);

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

  if (isLoading) {
    return (
      <Card>
        <CardContent className="p-10 text-center text-sm text-muted-foreground">Se încarcă review-urile...</CardContent>
      </Card>
    );
  }

  const reviews = data?.items ?? [];

  return (
    <div className="space-y-4">
      <div className="grid gap-4 md:grid-cols-3">
        <SummaryCard title="Total" value={data?.stats.total ?? 0} />
        <SummaryCard title="Publicate" value={data?.stats.published ?? 0} />
        <SummaryCard title="Ascunse" value={data?.stats.hidden ?? 0} />
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Review-uri film</CardTitle>
          <CardDescription>Review-urile trimise de utilizatori pentru acest titlu.</CardDescription>
        </CardHeader>
        <CardContent className="p-0">
          <ReviewsTable reviews={reviews} busyId={busyId} onStatus={setStatus} onDelete={remove} />
        </CardContent>
      </Card>
    </div>
  );
}

export function ReviewsTable({
  reviews,
  busyId,
  onStatus,
  onDelete,
}: {
  reviews: AdminContentReview[];
  busyId: number | null;
  onStatus: (review: AdminContentReview, status: "published" | "hidden") => void;
  onDelete: (review: AdminContentReview) => void;
}) {
  return (
    <Table>
      <TableHeader>
        <TableRow>
          <TableHead>Utilizator</TableHead>
          <TableHead>Rating</TableHead>
          <TableHead>Review</TableHead>
          <TableHead>Status</TableHead>
          <TableHead>Creat</TableHead>
          <TableHead className="text-right">Acțiuni</TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        {reviews.length === 0 ? (
          <TableRow>
            <TableCell colSpan={6} className="h-24 text-center text-muted-foreground">
              Nu există review-uri.
            </TableCell>
          </TableRow>
        ) : (
          reviews.map((review) => (
            <TableRow key={review.id}>
              <TableCell>
                <div className="font-medium">{review.user_name}</div>
                <div className="text-xs text-muted-foreground">{review.user_email ?? "Fără email"}</div>
              </TableCell>
              <TableCell>
                <div className="inline-flex items-center gap-1 font-medium">
                  <StarIcon className="h-4 w-4 fill-amber-400 text-amber-400" />
                  {review.rating}/5
                </div>
              </TableCell>
              <TableCell className="max-w-xl">
                <p className="line-clamp-3 text-sm text-muted-foreground">{review.comment}</p>
              </TableCell>
              <TableCell>
                <Badge variant={review.status === "published" ? "active" : "inactive"}>
                  {review.status === "published" ? "Publicat" : "Ascuns"}
                </Badge>
              </TableCell>
              <TableCell className="text-sm text-muted-foreground">
                {review.created_at ? new Date(review.created_at).toLocaleString() : "—"}
              </TableCell>
              <TableCell>
                <div className="flex justify-end gap-2">
                  {review.status === "published" ? (
                    <Button variant="outline" size="icon" disabled={busyId === review.id} onClick={() => onStatus(review, "hidden")} title="Ascunde">
                      <EyeOffIcon className="h-4 w-4" />
                    </Button>
                  ) : (
                    <Button variant="outline" size="icon" disabled={busyId === review.id} onClick={() => onStatus(review, "published")} title="Publică">
                      <EyeIcon className="h-4 w-4" />
                    </Button>
                  )}
                  <Button variant="outline" size="icon" disabled={busyId === review.id} onClick={() => onDelete(review)} title="Șterge">
                    <Trash2Icon className="h-4 w-4 text-destructive" />
                  </Button>
                </div>
              </TableCell>
            </TableRow>
          ))
        )}
      </TableBody>
    </Table>
  );
}

function SummaryCard({ title, value }: { title: string; value: number }) {
  return (
    <Card>
      <CardHeader className="pb-2">
        <CardDescription>{title}</CardDescription>
        <CardTitle>{value.toLocaleString()}</CardTitle>
      </CardHeader>
    </Card>
  );
}
