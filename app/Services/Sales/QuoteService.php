<?php

namespace App\Services\Sales;

use App\Enums\QuoteStatus;
use App\Exceptions\QuoteNotEditableException;
use App\Models\Quote;
use App\Models\User;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;


class QuoteService
{
    public const DEFAULT_PER_PAGE = 15;

    public function __construct(
        private readonly QuoteNumberGenerator $numberGenerator,
        private readonly CurrentTenant $tenant,
    ) {}


    public function create(array $data, User $author): Quote
    {
        return DB::transaction(function () use ($data, $author) {
            $quote = Quote::create([
                ...$data,
                'created_by' => $author->id,
                'folio' => $this->numberGenerator->next($this->tenant->id()),
                'status' => QuoteStatus::Draft,
            ]);

            return $quote->load('items');
        });
    }

    /**
     * Solo Draft y Sent son editables.
     *
     * @throws QuoteNotEditableException
     */
    public function update(Quote $quote, array $data): Quote
    {
        if (! $quote->isEditable()) {
            throw QuoteNotEditableException::forUpdate($quote);
        }

        $quote->update($data);

        return $quote->load('items');
    }

    /**
     * Solo Draft y Sent se pueden eliminar.
     *
     * @throws QuoteNotEditableException
     */
    public function delete(Quote $quote): void
    {
        if (! $quote->isEditable()) {
            throw QuoteNotEditableException::forDelete($quote);
        }

        $quote->delete();
    }

    /**
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function get(int|string $id): Quote
    {
        return Quote::with('items')->findOrFail($id);
    }

    public function all(): Collection
    {
        return Quote::with('items')->latest()->get();
    }

    public function paginated(int $page = 1, int $perPage = self::DEFAULT_PER_PAGE): LengthAwarePaginator
    {
        $page = max(1, $page);
        $perPage = max(1, min($perPage, 100));

        return Quote::with('items')
            ->latest()
            ->paginate(perPage: $perPage, page: $page);
    }
}