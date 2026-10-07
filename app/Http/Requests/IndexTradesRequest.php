<?php

namespace App\Http\Requests;

use App\Services\Trades\TradeService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexTradesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'view' => ['nullable', Rule::in([
                TradeService::VIEW_OPEN,
                TradeService::VIEW_IMPORTED,
                TradeService::VIEW_ALL,
                TradeService::VIEW_AWAITING_REVIEW,
            ])],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'amount_min' => ['nullable', 'numeric', 'min:0'],
            'amount_max' => ['nullable', 'numeric', 'min:0'],
            'title_search' => ['nullable', 'string', 'max:255'],
            'supplier_search' => ['nullable', 'string', 'max:255'],
            'game_search' => ['nullable', 'string', 'max:255'],
            'sort' => ['nullable', Rule::in(TradeService::SORTABLE_FIELDS)],
            'dir' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array{
     *   view: string,
     *   date_from: ?string,
     *   date_to: ?string,
     *   amount_min: ?string,
     *   amount_max: ?string,
     *   title_search: ?string,
     *   supplier_search: ?string,
     *   game_search: ?string,
     * }
     */
    public function filters(): array
    {
        return [
            'view' => $this->input('view', TradeService::VIEW_OPEN),
            'date_from' => $this->input('date_from'),
            'date_to' => $this->input('date_to'),
            'amount_min' => $this->input('amount_min'),
            'amount_max' => $this->input('amount_max'),
            'title_search' => $this->input('title_search'),
            'supplier_search' => $this->input('supplier_search'),
            'game_search' => $this->input('game_search'),
        ];
    }

    public function sortField(): string
    {
        return $this->input('sort', 'date');
    }

    public function sortDir(): string
    {
        return $this->input('dir', 'desc');
    }
}
