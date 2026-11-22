@php($rp = fn (int $n) => \App\Livewire\Pos\Kasir::rupiah($n))
<div class="app" x-data="kasir(@js($kasirConfig))"
     x-init="resetIdle()" x-on:pointerdown.window="resetIdle()" x-on:keydown.window="resetIdle()"
     x-on:paid.window="paid($event.detail)" x-on:pay-failed.window="payFailed($event.detail)" x-on:name-required.window="nameRequired()"
     x-on:held.window="orderOpen = false"
     x-on:correction-required.window="startCorrection($event.detail)"
     x-on:correction-failed.window="correctionFailed($event.detail)"
     x-on:correction-done.window="$refs.correction.close(); correction = null"
     x-on:pin-changed.window="$refs.pinChange.close()" x-on:pin-change-failed.window="pinChangeFailed($event.detail)"
     x-on:customer-saved.window="$refs.cust.close()" x-on:customer-failed.window="cust && (cust.error = $event.detail.message, cust.busy = false)"
     x-on:cash-done.window="$refs.kas.close(); kas = null" x-on:cash-failed.window="kasFailed($event.detail)"
     x-on:close-failed.window="closeFailed($event.detail)" x-on:shift-closed.window="$refs.close.close(); closing = null; $store.printer.print($event.detail.job)"
     x-on:print.window="$store.printer.print($event.detail.job)" x-on:reprint.window="$wire.reprint($event.detail.orderId)">
    <header class="topbar">
        <div class="brand">
            <span class="brand-icon" aria-hidden="true">
                <svg class="i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 8h1a4 4 0 1 1 0 8h-1"/><path d="M3 8h14v9a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4Z"/><path d="M6 2v2M10 2v2M14 2v2"/></svg>
            </span>
            <span><span>{{ $storeName }}</span><small id="cashier">{{ __('pos.kasir.app', ['name' => $user->name]) }}</small></span>
        </div>
        <span class="spacer"></span>
        <span class="chip hide-sm" title="{{ __('pos.kasir.clock') }}"
              x-data="{ now: new Date() }" x-init="setInterval(() => now = new Date(), 10000)">
            <span x-text="now.toLocaleTimeString(@js(str_replace('_', '-', app()->getLocale())), { hour: '2-digit', minute: '2-digit' })">--:--</span>
        </span>
        <button @class(['chip', 'ok' => $shift]) id="shift-chip" type="button" title="{{ __('pos.kasir.shift_hint') }}" @if ($shift) x-on:click="$refs.shiftDlg.showModal()" @endif>
            <span class="dot"></span><span id="shift-label">{{ $shift ? __('pos.kasir.shift_label', ['name' => $shift->name]) : __('pos.kasir.shift_closed') }}</span>
        </button>
        <button class="chip" id="history-chip" type="button" x-on:click="$wire.selectHistory(null); $refs.history.showModal()">
            <span>{{ __('pos.history.button') }}</span>
        </button>
        <button class="chip" id="printer-chip" type="button" title="{{ __('pos.kasir.printer_hint') }}" wire:ignore
                x-bind:class="{ ok: $store.printer.status === 'ok', busy: $store.printer.status === 'busy' }" x-on:click="$refs.printerDlg.showModal()">
            <span class="dot"></span><span id="printer-label" x-text="$store.printer.label">{{ __('pos.kasir.printer_none') }}</span>
        </button>
        <button class="icon-btn" type="button" aria-label="{{ __('pos.kasir.theme') }}" x-on:click="bangkuToggleTheme()">
            <svg class="i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/></svg>
        </button>
        @php($pinOutdated = (int) $user->pin_length !== (int) $kasirConfig['pinLength'])
        <button @class(['icon-btn', 'needs-action' => $pinOutdated]) id="pin-btn" type="button" aria-label="{{ __('pos.pin_change.button') }}"
                title="{{ $pinOutdated ? __('pos.pin_change.outdated', ['length' => $kasirConfig['pinLength']]) : __('pos.pin_change.button') }}" x-on:click="openPinChange()">
            <svg class="i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="7.5" cy="15.5" r="4.5"/><path d="m10.7 12.3 9.3-9.3M16 7l3 3M14 9l2 2"/></svg>
        </button>
        <form method="POST" action="{{ route('pos.lock') }}" x-ref="lock" style="display: contents">
            @csrf
            <button class="icon-btn" id="lock-btn" type="submit" aria-label="{{ __('pos.kasir.lock') }}" title="{{ __('pos.kasir.lock') }}">
                <svg class="i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
            </button>
        </form>
    </header>

    <main class="main">
        <section class="panel" aria-label="Menu">
            <div class="menu-head">
                <div class="tabs" role="tablist" id="tabs">
                    <button class="tab" role="tab" type="button" x-bind:aria-selected="cat === ''" x-on:click="cat = ''">{{ __('pos.order.all') }}</button>
                    @foreach ($categories as $c)
                        <button class="tab" role="tab" type="button" data-cat="{{ $c->id }}" x-bind:aria-selected="cat === {{ $c->id }}" x-on:click="cat = {{ $c->id }}">{{ $c->name }}</button>
                    @endforeach
                </div>
                <label class="search">
                    <svg class="i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
                    <input id="q" type="search" placeholder="{{ __('pos.order.search') }}" autocomplete="off" aria-label="{{ __('pos.order.search') }}" x-model="q">
                </label>
            </div>
            <div class="grid" id="grid">
                @foreach ($menu as $item)
                    <button class="item" type="button" data-id="{{ $item->id }}" wire:key="item-{{ $item->id }}" @disabled($item->is_sold_out)
                            x-show="visible({{ $item->category_id }}, @js(mb_strtolower($item->name)))" x-on:click="pick({{ $item->id }})">
                        <span class="thumb" aria-hidden="true">@if ($url = $imageUrl($item))<img src="{{ $url }}" alt="">@else{{ $item->icon }}@endif</span>
                        <span class="item-name">{{ $item->name }}</span>
                        <span class="item-meta"><span class="price">{{ $rp($item->price) }}</span>@if ($item->is_sold_out)<span class="badge">{{ __('pos.order.sold_out') }}</span>@elseif ($item->is_best_seller)<span class="badge">{{ __('pos.order.best') }}</span>@endif</span>
                    </button>
                @endforeach
                <p class="empty" x-show="noResults" x-cloak>{{ __('pos.order.not_found') }}</p>
            </div>
        </section>

        @php($t = $priced['totals'])
        <aside class="panel order" id="order" aria-label="Pesanan" x-bind:class="{ open: orderOpen }">
            <div class="order-head">
                <div class="order-title">
                    <div class="queue"><b id="queue-no">#{{ $heldOrder?->queue_number ?? $queue }}</b><span id="order-no">{{ $heldOrder ? $heldOrder->code.' · '.__('pos.held.saved_tag') : $orderCode }}</span></div>
                    @if ($held->isNotEmpty())
                        <button class="held-btn" type="button" id="held-btn" x-on:click="$refs.held.showModal()">{{ __('pos.held.button') }} <b id="held-count">{{ $held->count() }}</b></button>
                    @endif
                    <button class="icon-btn order-close" type="button" id="order-close" aria-label="{{ __('pos.order.close') }}" x-on:click="orderOpen = false">
                        <svg class="i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                    </button>
                </div>
                <div class="name-field" id="name-field">
                    <div class="name-row">
                        <input class="input" id="customer" wire:model.blur="customerName" placeholder="{{ __('pos.order.customer') }}{{ $requireName ? ' *' : '' }}" autocomplete="off" maxlength="{{ \App\Livewire\Pos\Kasir::NAME_MAX }}" aria-label="{{ __('pos.order.customer') }}" aria-required="{{ $requireName ? 'true' : 'false' }}"
                               x-on:input="$event.target.value.trim() && document.getElementById('name-field').classList.remove('invalid'); suggestCustomers($event.target.value)"
                               x-on:blur="hideSuggestions()" x-on:keydown.escape="hideSuggestions()">
                        @if ($customersOn && ! $linkedCustomer)
                            <button class="icon-btn" type="button" id="cust-btn" aria-label="{{ __('pos.customers.save_title') }}" title="{{ __('pos.customers.save_title') }}" x-on:click="openCustomer()">
                                <svg class="i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="4"/><path d="M2 21a7 7 0 0 1 14 0M19 8v6M16 11h6"/></svg>
                            </button>
                        @endif
                    </div>
                    <div class="suggest" id="suggest" wire:ignore x-show="suggestions.length" x-cloak>
                        <template x-for="c in suggestions" :key="c.id">
                            <button type="button" :data-cust="c.id" x-on:pointerdown.prevent x-on:click="pickCustomer(c)"><b x-text="c.name"></b><span x-text="customerLine(c)"></span></button>
                        </template>
                    </div>
                    @if ($linkedCustomer)
                        <div class="cust-tag" id="cust-tag">
                            <span id="cust-tag-text">{{ collect([
                                $linkedCustomer->name,
                                $linkedCustomer->maskedPhone(),
                                __('pos.customers.visits', ['count' => $linkedCustomer->visits_count]),
                                $loyalty ? __('pos.customers.points', ['count' => $linkedCustomer->points]) : null,
                                $linkedCustomer->notes,
                            ])->filter(fn ($v) => $v !== null && $v !== '')->implode(' · ') }}</span>
                            <button type="button" class="link-btn" id="cust-unlink" wire:click="unlinkCustomer">{{ __('pos.customers.unlink') }}</button>
                        </div>
                    @endif
                    <span class="hint">{{ __('pos.order.name_hint') }}</span>
                </div>
                <div class="field">
                    <div class="seg" role="group" aria-label="{{ __('pos.order.types') }}" id="types" style="flex: 1; grid-template-columns: repeat({{ max(1, $orderTypes->count()) }}, 1fr)">
                        @foreach ($orderTypes as $type)
                            <button type="button" data-type="{{ $type->id }}" aria-pressed="{{ $order['type_id'] === $type->id ? 'true' : 'false' }}" wire:click="setType({{ $type->id }})">{{ $type->name }}</button>
                        @endforeach
                    </div>
                    @if ($orderTypes->contains('needs_table', true))
                        <input class="input" id="table" placeholder="{{ __('pos.order.table') }}" inputmode="none" readonly value="{{ $order['table'] }}" style="max-width: 96px" aria-label="{{ __('pos.order.table_keypad') }}"
                               @disabled(! $priced['type']?->needs_table) x-on:click="editTable(@js(__('pos.order.table_keypad')), @js($order['table']))">
                    @endif
                </div>
            </div>
            <div class="lines" id="lines">
                @forelse ($priced['lines'] as $line)
                    <div class="line" wire:key="line-{{ $line['index'] }}-{{ $line['item_id'] }}">
                        @if ($line['editable'])
                            <button type="button" class="line-main" data-edit="{{ $line['index'] }}" aria-label="{{ __('pos.order.edit_options', ['item' => $line['name']]) }}"
                                    x-on:click="pick({{ $line['item_id'] }}, @js(['index' => $line['index'], 'picks' => (object) $line['picks'], 'qty' => $line['qty'], 'note' => $line['note']]))">
                        @else
                            <div class="line-main">
                        @endif
                            <span class="line-name">{{ $line['name'] }}@if ($line['printed'] >= $line['qty']) <span class="badge badge-printed">{{ __('pos.held.printed') }}</span>@endif @if ($line['unavailable']) <span class="badge">{{ __('pos.order.unavailable_badge') }}</span>@endif</span>
                            @if ($line['labels'])<span class="line-opts">{{ implode(' · ', $line['labels']) }}</span>@endif
                        @if ($line['editable'])</button>@else</div>@endif
                        <div class="line-right">
                            <span class="line-total">{{ $rp($line['total']) }}</span>
                            <span class="qty">
                                <button type="button" data-dec="{{ $line['index'] }}" aria-label="{{ __('pos.order.dec', ['item' => $line['name']]) }}" wire:click="changeQty({{ $line['index'] }}, -1)" x-on:click="bangkuHaptic()">−</button>
                                <output aria-label="{{ __('pos.order.qty') }}">{{ $line['qty'] }}</output>
                                <button type="button" data-inc="{{ $line['index'] }}" aria-label="{{ __('pos.order.inc', ['item' => $line['name']]) }}" wire:click="changeQty({{ $line['index'] }}, 1)" x-on:click="bangkuHaptic()">+</button>
                            </span>
                        </div>
                    </div>
                @empty
                    <p class="empty">{!! __('pos.order.empty') !!}</p>
                @endforelse
            </div>
            <div class="summary">
                <div class="row muted"><span>{{ __('pos.order.subtotal') }}</span><span id="subtotal">{{ $rp($t->subtotal) }}</span></div>
                <div class="row muted"><button class="pill" type="button" id="discount-btn" x-on:click="openDiscount(@js($order['discount']))">{{ __('pos.order.discount') }}{{ $order['discount']['type'] === 'percent' ? ' '.$order['discount']['value'].'%' : '' }}</button><span id="discount">− {{ $rp($t->discount) }}</span></div>
                @if ($loyalty && $linkedCustomer && ($linkedCustomer->points >= $redeemMin || $order['points']))
                    @php($maxPoints = min($linkedCustomer->points, $pointValue > 0 ? intdiv($t->subtotal - $t->discount, $pointValue) : 0))
                    <div class="row muted" id="points-row">
                        <button class="pill" type="button" id="points-btn" x-on:click="editPoints(@js(__('pos.points.keypad', ['balance' => $linkedCustomer->points, 'max' => $maxPoints, 'value' => $rp($pointValue)])), {{ $order['points'] ?: $maxPoints }})">{{ $order['points'] ? __('pos.points.applied', ['points' => $order['points']]) : __('pos.points.button', ['balance' => $linkedCustomer->points]) }}</button>
                        <span id="points">− {{ $rp($t->points) }}</span>
                    </div>
                @endif
                @if ($t->serviceRate)<div class="row muted" id="service-row"><span>{{ $charges['service_label'] }} {{ $t->serviceRate + 0 }}%</span><span id="service">{{ $rp($t->service) }}</span></div>@endif
                @if ($t->taxRate)<div class="row muted" id="tax-row"><span>{{ $charges['tax_label'] }} {{ $t->taxRate + 0 }}%</span><span id="tax">{{ $rp($t->tax) }}</span></div>@endif
                <div class="row total"><span>{{ __('pos.order.total') }}</span><span id="total">{{ $rp($t->total) }}</span></div>
            </div>
            <div class="pay">
                <div class="methods" role="group" aria-label="{{ __('pos.pay.methods') }}" id="methods">
                    <template x-for="m in cfg.methods" :key="m.id">
                        <button class="method" type="button" :data-method="m.id" :aria-pressed="method === m.id" x-on:click="method = m.id" x-text="m.name"></button>
                    </template>
                </div>
            </div>
            <div class="actions three">
                <button class="btn" type="button" id="clear" title="{{ __('pos.order.clear') }}" aria-label="{{ __('pos.order.clear') }}" @disabled(! $priced['count'])
                        x-on:click="{{ $heldOrder ? "confirm(".e(\Illuminate\Support\Js::from(__('pos.held.close_title'))).", ".e(\Illuminate\Support\Js::from(__('pos.held.close_text'))).", ".e(\Illuminate\Support\Js::from(__('pos.held.close_ok'))).")" : "confirm(".e(\Illuminate\Support\Js::from(__('pos.order.clear_title'))).", ".e(\Illuminate\Support\Js::from(__('pos.order.clear_text'))).", ".e(\Illuminate\Support\Js::from(__('pos.order.clear_ok'))).")" }}.then((ok) => ok && $wire.clearOrder())">
                    <svg class="i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14"/></svg>
                </button>
                <button class="btn" type="button" id="hold" title="{{ __('pos.held.hold_hint') }}" @disabled(! $priced['count']) x-on:click="hold()">{{ __('pos.order.hold') }}</button>
                <button class="btn btn-primary" type="button" id="checkout" @disabled(! $priced['count'])
                        x-on:click="openPay({{ $t->total }}, {{ $priced['count'] }}, @js('#'.($heldOrder?->queue_number ?? $queue).' · ').concat(document.getElementById('customer').value.trim()))">
                    <svg class="i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M6 14h12v8H6z"/></svg>
                    <span id="checkout-label">{{ $priced['count'] ? __('pos.order.pay_total', ['total' => $rp($t->total)]) : __('pos.order.pay') }}</span>
                </button>
            </div>
        </aside>
    </main>

    <button class="cart-bar" id="cart-bar" type="button" x-on:click="orderOpen = true"><span id="cart-bar-count">{{ __('pos.order.items', ['queue' => $queue, 'count' => $priced['count']]) }}</span><span id="cart-bar-total">{{ $rp($t->total) }}</span></button>

    @unless ($shift)
        {{-- Buka shift: wajib, tidak bisa ditutup (Esc) sebelum shift dibuka. --}}
        <dialog id="open-dlg" class="shift-dlg" wire:key="open-shift"
                x-data="openShift(@js(['denoms' => $denominations, 'suggested' => $suggested, 'handover' => $handover]))"
                x-init="$el.showModal()" x-on:cancel.prevent>
            <div class="dlg-head"><div><h3>{{ __('pos.shift.open_title') }}</h3><p id="open-sub">{{ __('pos.shift.open_sub', ['date' => now()->translatedFormat('l, j F Y'), 'name' => $user->name]) }}</p></div></div>
            <div class="dlg-body">
                <div class="opt-group"><span>{{ __('pos.shift.template') }}</span>
                    <div class="seg" role="group" aria-label="{{ __('pos.shift.template') }}" id="open-shift" style="grid-template-columns: repeat({{ max(1, $templates->count()) }}, 1fr)">
                        @foreach ($templates as $t)
                            <button type="button" data-shift="{{ $t->id }}" x-bind:aria-pressed="template === {{ $t->id }}" x-on:click="template = {{ $t->id }}">{{ $t->name }}</button>
                        @endforeach
                    </div>
                    @error('template')<small class="field-error">{{ $message }}</small>@enderror
                </div>
                <div class="opt-group">
                    <span>{{ __('pos.shift.count_title') }}</span>
                    @if ($handover || $openBills['count'])
                        <div class="handover" id="open-handover">
                            @if ($handover){{ __('pos.shift.handover', ['name' => $handover['name'], 'cashier' => $handover['cashier'], 'amount' => $rp($handover['amount'])]) }}@endif
                            @if ($handover && $openBills['count'])<br>@endif
                            @if ($openBills['count']){{ __('pos.shift.open_bills', ['count' => $openBills['count'], 'total' => $rp($openBills['total'])]) }}@endif
                        </div>
                    @endif
                    <div class="denoms" id="open-denoms">
                        @foreach ($denominations as $d)
                            <div class="denom">
                                <span class="lbl">{{ number_format($d, 0, ',', '.') }}<small x-text="rp({{ $d }} * (counts[{{ $d }}] || 0))"></small></span>
                                <button type="button" class="pm" data-d="{{ $d }}" data-step="-1" aria-label="{{ __('pos.shift.minus', ['value' => $rp($d)]) }}" x-on:click="step({{ $d }}, -1)">−</button>
                                <button type="button" class="n" data-d="{{ $d }}" aria-label="{{ __('pos.shift.sheets', ['value' => $rp($d)]) }}" x-on:click="editCount({{ $d }}, @js(__('pos.shift.sheets_keypad', ['value' => $rp($d)])))" x-text="counts[{{ $d }}] || 0">0</button>
                                <button type="button" class="pm" data-d="{{ $d }}" data-step="1" aria-label="{{ __('pos.shift.plus', ['value' => $rp($d)]) }}" x-on:click="step({{ $d }}, 1)">+</button>
                            </div>
                        @endforeach
                        <div class="denom coins"><span class="lbl">{{ __('pos.shift.coins') }}<small>{{ __('pos.shift.coins_hint') }}</small></span>
                            <button type="button" class="money-btn" data-coins x-on:click="editCoins(@js(__('pos.shift.coins_keypad')))" x-text="rp(coins)">Rp 0</button>
                        </div>
                    </div>
                    @error('counts')<small class="field-error">{{ $message }}</small>@enderror
                </div>
                <div class="count-total">
                    <span>{{ __('pos.shift.opening_float') }}<small id="open-diff" x-text="diffText(@js(__('pos.shift.diff_less', ['amount' => '__A__'])), @js(__('pos.shift.diff_more', ['amount' => '__A__'])))"></small></span>
                    <b id="open-total" x-text="rp(total)">Rp 0</b>
                </div>
                <label class="opt-group" id="open-note-wrap" x-show="diff !== 0" x-cloak>
                    <span>{{ __('pos.shift.note') }} *</span>
                    <input class="input" id="open-note" maxlength="{{ \App\Support\ShiftService::NOTE_MAX }}" placeholder="{{ __('pos.shift.note_placeholder') }}" x-model="note">
                    @error('note')<small class="field-error">{{ $message }}</small>@enderror
                </label>
                @error('shift')<p class="field-error">{{ $message }}</p>@enderror
            </div>
            <div class="dlg-foot">
                <button class="btn" type="button" id="open-logout" x-on:click="$refs.lock.submit()">{{ __('pos.shift.switch_cashier') }}</button>
                <button class="btn" type="button" id="open-test" x-on:click="$wire.testPrint()">{{ __('pos.printer.test') }}</button>
                <button class="btn btn-primary" type="button" id="open-ok" style="flex: 1" x-bind:disabled="busy || !template || (diff !== 0 && !note.trim())" x-on:click="submit()">{{ __('pos.shift.open') }}</button>
            </div>
        </dialog>
    @endunless

    {{-- Pilihan varian: dipilih dulu, baru masuk pesanan. Server memvalidasi ulang (OrderDraft). --}}
    <dialog id="opt-dlg" wire:ignore x-ref="opt" x-on:close="editing = null">
        <template x-if="editing">
            <div>
                <div class="dlg-head"><div><h3 id="opt-title" x-text="editing.item.name"></h3><p id="opt-price" x-text="rp(editing.item.price)"></p></div></div>
                <div class="dlg-body" id="opt-body">
                    <template x-for="g in editing.item.groups" :key="g.id">
                        <div class="opt-group"><span x-text="g.name"></span>
                            <div class="opts" :data-group="g.id">
                                <template x-for="o in g.options" :key="o.id">
                                    <button type="button" class="opt" :data-option="o.id" :aria-pressed="editing.picks[g.id] === o.id" x-on:click="editing.picks[g.id] = o.id">
                                        <span x-text="o.name"></span><small x-show="o.price" x-text="'+' + rp(o.price).replace('Rp ', '')"></small>
                                    </button>
                                </template>
                            </div>
                        </div>
                    </template>
                    <label class="opt-group"><span x-text="@js(__('pos.order.note_for', ['station' => '__S__'])).replace('__S__', editing.item.station.toLowerCase())"></span>
                        <input class="input" id="opt-note" maxlength="{{ \App\Support\OrderDraft::NOTE_MAX }}" placeholder="{{ __('pos.order.note_placeholder') }}" x-model="editing.note">
                    </label>
                    <div class="qty-row"><span class="opt-group"><span>{{ __('pos.order.qty') }}</span></span>
                        <span class="qty"><button type="button" data-q="-1" aria-label="−" x-on:click="editing.qty = Math.max(1, editing.qty - 1)">−</button><output id="opt-qty" x-text="editing.qty"></output><button type="button" data-q="1" aria-label="+" x-on:click="editing.qty = Math.min({{ \App\Support\OrderDraft::MAX_QTY }}, editing.qty + 1)">+</button></span>
                    </div>
                </div>
                <div class="dlg-foot">
                    <button class="btn" type="button" x-on:click="$refs.opt.close()">{{ __('pos.order.cancel') }}</button>
                    <button class="btn btn-primary" id="opt-save" type="button" style="flex: 1" x-on:click="saveOptions()"
                            x-text="(editing.index === null ? @js(__('pos.order.add', ['total' => '__T__'])) : @js(__('pos.order.save', ['total' => '__T__']))).replace('__T__', rp(optionTotal()))"></button>
                </div>
            </div>
        </template>
    </dialog>

    {{-- Diskon: persen atau rupiah; di atas batas → PIN persetujuan. --}}
    <dialog id="disc-dlg" wire:ignore x-ref="disc">
        <div class="dlg-head"><div><h3>{{ __('pos.order.discount_title') }}</h3><p>{{ __('pos.order.discount_sub') }}</p></div></div>
        <div class="dlg-body">
            <div class="opts" id="disc-quick">
                <button type="button" class="opt" data-v="" x-on:click="discountInput = ''">{{ __('pos.order.discount_none') }}</button>
                @foreach ($presets as $preset)
                    @php($v = $preset->type === \App\Enums\DiscountType::Percent ? $preset->value.'%' : (string) $preset->value)
                    <button type="button" class="opt" data-v="{{ $v }}" x-on:click="discountInput = @js($v)">{{ $preset->type === \App\Enums\DiscountType::Percent ? $v : $rp($preset->value) }}</button>
                @endforeach
            </div>
            <input class="input" id="disc-input" placeholder="{{ __('pos.order.discount_placeholder') }}" aria-label="{{ __('pos.order.discount_title') }}" x-model="discountInput">
        </div>
        <div class="dlg-foot"><button class="btn" type="button" x-on:click="$refs.disc.close()">{{ __('pos.order.cancel') }}</button><button class="btn btn-primary" type="button" id="disc-ok" x-on:click="applyDiscount()">{{ __('pos.order.discount_apply') }}</button></div>
    </dialog>

    {{-- PIN persetujuan (owner/kepala toko) — dicek di server (App\Support\Approval). --}}
    <dialog id="pin-dlg" class="keypad-dlg" x-ref="approval" wire:ignore
            x-on:approval-required.window="pin = ''; approvalSub = $event.detail.text; approvalError = ''; $refs.approval.showModal()"
            x-on:approval-done.window="$refs.approval.close()"
            x-on:approval-failed.window="pin = ''; approvalError = $event.detail.message; bangkuHaptic(60)"
            x-on:cancel.prevent="$refs.approval.close(); $wire.cancelApproval()">
        <div class="dlg-head"><div><h3 id="pin-title">{{ __('pos.order.approval_title') }}</h3><p id="pin-sub" x-text="approvalSub"></p></div></div>
        <div class="dlg-body">
            <output class="keypad-display pin-display" id="pin-display" x-text="pin ? '•'.repeat(pin.length) : '–'">–</output>
            <p class="pin-error" id="pin-error" x-show="approvalError" x-text="approvalError"></p>
            <div class="keypad">
                @foreach (['1', '2', '3', '4', '5', '6', '7', '8', '9'] as $k)<button type="button" data-p="{{ $k }}" x-on:click="pressPin('{{ $k }}')">{{ $k }}</button>@endforeach
                <button type="button" data-p="clear" x-on:click="pin = ''">{{ __('pos.keypad.clear') }}</button>
                <button type="button" data-p="0" x-on:click="pressPin('0')">0</button>
                <button type="button" data-p="back" aria-label="{{ __('pos.keypad.backspace') }}" x-on:click="pin = pin.slice(0, -1)">⌫</button>
            </div>
        </div>
        <div class="dlg-foot"><button class="btn" type="button" data-p="cancel" style="flex: 1" x-on:click="$refs.approval.close(); $wire.cancelApproval()">{{ __('pos.order.cancel') }}</button></div>
    </dialog>

    {{-- Konfirmasi --}}
    <dialog id="confirm-dlg" wire:ignore x-ref="confirm" x-on:close="confirmDone($refs.confirm.returnValue === 'ok')">
        <form method="dialog">
            <div class="dlg-head"><div><h3 id="confirm-title" x-text="confirmText.title"></h3><p id="confirm-text" x-text="confirmText.text"></p></div></div>
            <div class="dlg-foot"><button class="btn" value="cancel">{{ __('pos.order.cancel') }}</button><button class="btn btn-primary" value="ok" id="confirm-ok" x-text="confirmText.ok">{{ __('pos.order.yes') }}</button></div>
        </form>
    </dialog>

    {{-- Bayar: tunai (keypad, uang cepat, kembalian langsung) atau EDC/QRIS (nominal besar, bank). Server menghitung ulang (Checkout). --}}
    <dialog id="pay-dlg" class="pay-dlg" wire:ignore x-ref="pay" x-on:close="payState = null">
        <template x-if="payState">
            <div>
                <div class="dlg-head"><div><h3 id="pay-title" x-text="payTitle()"></h3><p id="pay-sub" x-text="payState.sub"></p></div></div>
                <div class="dlg-body" id="pay-body">
                    <template x-if="!payState.method.cash">
                        <div style="display: contents">
                            <div class="pay-total"><span x-text="payState.method.qr ? @js(__('pos.pay.qr_amount')) : @js(__('pos.pay.edc_amount'))"></span><b x-text="rp(payState.total)"></b></div>
                            <template x-if="payState.method.qr">
                                <img class="pay-qr" id="pay-qr" :src="payState.method.qr" :alt="payState.method.name">
                            </template>
                            <template x-if="payState.method.bank">
                                <div class="opt-group"><span x-text="@js(__('pos.pay.bank', ['method' => '__M__'])).replace('__M__', payState.method.name.toLowerCase()) + ' *'"></span>
                                    <div class="banks opts" id="banks">
                                        <template x-for="b in cfg.banks" :key="b.id">
                                            <button type="button" class="opt" :data-bank="b.id" :aria-pressed="payState.bank === b.id" x-on:click="payState.bank = b.id" x-text="b.name"></button>
                                        </template>
                                    </div>
                                </div>
                            </template>
                            <p class="edc-note" x-text="payState.method.qr ? @js(__('pos.pay.qr_note')) : @js(__('pos.pay.edc_note'))"></p>
                        </div>
                    </template>
                    <template x-if="payState.method.cash">
                        <div style="display: contents">
                            <div class="pay-total"><span>{{ __('pos.pay.total') }}</span><b x-text="rp(payState.total)"></b></div>
                            <div class="cash-grid">
                                <div class="cash-side">
                                    <div class="received"><span>{{ __('pos.pay.received') }}</span><b id="received" x-text="rp(Number(payState.received) || 0)"></b></div>
                                    <div class="quick" id="cash-quick">
                                        <template x-for="(v, i) in quickAmounts()" :key="v">
                                            <button type="button" :data-paid="v" x-on:click="payState.received = String(v)" x-text="i === 0 ? @js(__('pos.pay.exact')) : rp(v).replace('Rp ', '')"></button>
                                        </template>
                                    </div>
                                    <div class="change-live" id="change-live" :class="{ short: changeDue() < 0 }">
                                        <span x-text="changeDue() < 0 ? @js(__('pos.pay.short')) : @js(__('pos.pay.change'))"></span><b x-text="rp(Math.abs(changeDue()))"></b>
                                    </div>
                                </div>
                                <div class="keypad" id="cash-keypad">
                                    @foreach (['1', '2', '3', '4', '5', '6', '7', '8', '9', '000', '0'] as $k)<button type="button" data-k="{{ $k }}" x-on:click="pressCash('{{ $k }}')">{{ $k }}</button>@endforeach
                                    <button type="button" data-k="back" aria-label="{{ __('pos.keypad.backspace') }}" x-on:click="pressCash('back')">⌫</button>
                                </div>
                            </div>
                        </div>
                    </template>
                    <button type="button" class="switch-row" role="switch" id="receipt-switch" :aria-checked="payState.receipt" x-on:click="payState.receipt = !payState.receipt"><span>{{ __('pos.pay.receipt') }}</span><span class="switch" aria-hidden="true"></span></button>
                    <p class="field-error" id="pay-error" x-show="payState.error" x-text="payState.error"></p>
                </div>
                <div class="dlg-foot">
                    <button class="btn" type="button" id="pay-cancel" x-on:click="$refs.pay.close()">{{ __('pos.pay.cancel') }}</button>
                    <button class="btn btn-primary" type="button" id="pay-ok" style="flex: 1" :disabled="!canPay()" x-on:click="submitPay()"
                            x-text="payState.method.cash ? @js(__('pos.pay.ok_cash')) : @js(__('pos.pay.ok_edc'))"></button>
                </div>
            </div>
        </template>
    </dialog>

    {{-- Kembalian (tunai): layar besar sampai diketuk Selesai. --}}
    <dialog id="change-dlg" class="change-dlg" wire:ignore x-ref="change" x-on:cancel.prevent>
        <div class="dlg-body">
            <div class="change-label">{{ __('pos.pay.change') }}</div>
            <div class="change-amount" id="change-amount" x-text="rp(changeInfo.change)"></div>
            <div class="change-meta" id="change-meta" x-text="@js(__('pos.pay.change_meta', ['received' => '__R__', 'total' => '__T__'])).replace('__R__', rp(changeInfo.received)).replace('__T__', rp(changeInfo.total))"></div>
        </div>
        <div class="dlg-foot"><button class="btn btn-primary" type="button" id="change-done" x-on:click="$refs.change.close(); orderOpen = false; toastReprint(changeInfo); $store.printer.flush()">{{ __('pos.pay.finish') }}</button></div>
    </dialog>

    {{-- Pesanan tersimpan (open bill). wire:ignore.self: daftar diperbarui tanpa menutup dialog. --}}
    <dialog id="held-dlg" wire:ignore.self x-ref="held">
        <div class="dlg-head"><div><h3>{{ __('pos.held.title') }}</h3><p>{{ __('pos.held.sub') }}</p></div></div>
        <div class="dlg-body"><div class="held-list" id="held-list">
            @forelse ($held as $h)
                @php($age = (int) $h->business_date->diffInDays(today()))
                <button type="button" class="held-item" data-order="{{ $h->id }}" wire:key="held-{{ $h->id }}"
                        x-on:click="openHeld({{ $h->id }}, {{ $priced['count'] && ! $order['order_id'] ? 'true' : 'false' }})">
                    <b>#{{ $h->queue_number }} · {{ $h->customer_name }}
                        @if ($age > $maxDays)<span class="badge badge-late">{{ __('pos.held.overdue', ['days' => $age]) }}</span>
                        @elseif ($age)<span class="badge badge-late">{{ __('pos.held.late', ['days' => $age]) }}</span>
                        @elseif ($shift && $h->opened_shift_id !== $shift->id)<span class="badge">{{ __('pos.held.carried') }}</span>@endif
                    </b>
                    <span class="amt">{{ $rp($h->total) }}</span>
                    <span>{{ $h->code }} · {{ $h->order_type_name }}@if ($h->table_number) · {{ __('pos.held.table', ['table' => $h->table_number]) }}@endif · {{ __('pos.held.items', ['count' => $h->items_count]) }} · {{ $age ? $h->created_at->translatedFormat('D j M').' ' : '' }}{{ $h->created_at->format('H:i') }}</span>
                </button>
            @empty
                <p class="empty">{{ __('pos.held.empty') }}</p>
            @endforelse
        </div></div>
        <div class="dlg-foot"><button class="btn" type="button" x-on:click="$refs.held.close()">{{ __('pos.held.close') }}</button></div>
    </dialog>

    {{-- Riwayat hari ini + open bill (keputusan pemilik: daftar transaksi juga di kasir). --}}
    <dialog id="history-dlg" class="shift-dlg" wire:ignore.self x-ref="history">
        @if ($historyOrder)
            @php($ho = $historyOrder)
            <div class="dlg-head"><div><h3 id="history-title">#{{ $ho->queue_number }} · {{ $ho->customer_name }}</h3>
                <p>{{ $ho->code }} · {{ $ho->status->label() }} · {{ $ho->created_at->format('H:i') }} · {{ __('pos.history.cashier', ['name' => ($ho->cashier ?? $ho->creator)?->name]) }}</p></div></div>
            <div class="dlg-body">
                <div class="lines">
                    @foreach ($ho->items as $item)
                        <div class="line"><div class="line-main"><span class="line-name">{{ $item->quantity }}× {{ $item->name }}</span>
                            @if ($item->labels())<span class="line-opts">{{ implode(' · ', $item->labels()) }}</span>@endif</div>
                            <div class="line-right"><span class="line-total">{{ $rp($item->unit_price * $item->quantity) }}</span></div></div>
                    @endforeach
                </div>
                <div class="summary">
                    <div class="row muted"><span>{{ __('pos.order.subtotal') }}</span><span>{{ $rp($ho->subtotal) }}</span></div>
                    @if ($ho->discount_amount)<div class="row muted"><span>{{ __('pos.order.discount') }}</span><span>− {{ $rp($ho->discount_amount) }}</span></div>@endif
                    @if ($ho->points_discount)<div class="row muted"><span>{{ __('pos.print.points', ['points' => $ho->points_redeemed]) }}</span><span>− {{ $rp($ho->points_discount) }}</span></div>@endif
                    @if ($ho->service_amount)<div class="row muted"><span>{{ $charges['service_label'] }}</span><span>{{ $rp($ho->service_amount) }}</span></div>@endif
                    @if ($ho->tax_amount)<div class="row muted"><span>{{ $charges['tax_label'] }}</span><span>{{ $rp($ho->tax_amount) }}</span></div>@endif
                    <div class="row total"><span>{{ __('pos.order.total') }}</span><span>{{ $rp($ho->total) }}</span></div>
                    @foreach ($ho->payments as $pay)
                        <div class="row muted"><span>{{ $pay->method_name }}{{ $pay->bank_name ? ' '.$pay->bank_name : '' }}</span><span>{{ $rp($pay->received) }}</span></div>
                        @if ($pay->change)<div class="row muted"><span>{{ __('pos.history.change') }}</span><span>{{ $rp($pay->change) }}</span></div>@endif
                    @endforeach
                    @foreach ($ho->refunds as $refund)<p class="handover">{{ __('pos.history.refunded_by', ['time' => $refund->created_at->format('H:i'), 'reason' => $refund->reason_label]) }}</p>@endforeach
                    @if ($ho->status === \App\Enums\OrderStatus::Voided)<p class="handover">{{ __('pos.history.voided', ['time' => $ho->voided_at->format('H:i'), 'reason' => $ho->void_reason_label]) }}</p>@endif
                </div>
            </div>
            <div class="dlg-foot">
                <button class="btn" type="button" id="history-back" wire:click="selectHistory(null)">{{ __('pos.history.back') }}</button>
                @if ($ho->status !== \App\Enums\OrderStatus::Voided)<button class="btn" type="button" id="history-reprint" wire:click="reprint({{ $ho->id }})">{{ __('pos.printer.reprint') }}</button>@endif
                @if ($ho->refundableToday())<button class="btn btn-danger" type="button" id="history-refund" style="flex: 1" wire:click="startRefund({{ $ho->id }})">{{ __('pos.history.refund') }}</button>@endif
                @if ($ho->status === \App\Enums\OrderStatus::Open)<button class="btn btn-danger" type="button" id="history-void" style="flex: 1" wire:click="startVoid({{ $ho->id }})">{{ __('pos.history.void') }}</button>@endif
            </div>
        @else
            <div class="dlg-head"><div><h3>{{ __('pos.history.title') }}</h3><p>{{ __('pos.history.sub') }}</p></div></div>
            <div class="dlg-body"><div class="held-list" id="history-list">
                @forelse ($history as $h)
                    <button type="button" class="held-item" data-order="{{ $h->id }}" wire:key="hist-{{ $h->id }}" wire:click="selectHistory({{ $h->id }})">
                        <b>#{{ $h->queue_number }} · {{ $h->customer_name }}
                            @if ($h->status !== \App\Enums\OrderStatus::Paid)<span @class(['badge', 'badge-late' => $h->status !== \App\Enums\OrderStatus::Open])>{{ $h->status->label() }}</span>@endif</b>
                        <span class="amt">{{ $rp($h->total) }}</span>
                        <span>{{ $h->code }} · {{ $h->payments->pluck('method_name')->implode(', ') ?: $h->order_type_name }} · {{ ($h->paid_at ?? $h->created_at)->format('H:i') }}</span>
                    </button>
                @empty
                    <p class="empty">{{ __('pos.history.empty') }}</p>
                @endforelse
            </div></div>
            <div class="dlg-foot"><button class="btn" type="button" x-on:click="$refs.history.close()">{{ __('pos.history.close') }}</button></div>
        @endif
    </dialog>

    {{-- Koreksi: alasan (+ catatan) dan PIN penyetuju — semua dicek di server (OrderCorrections, Approval). --}}
    <dialog id="correction-dlg" class="keypad-dlg" wire:ignore x-ref="correction" x-on:cancel.prevent="closeCorrection()">
        <template x-if="correction">
            <div>
                <div class="dlg-head"><div><h3 id="correction-title" x-text="correction.title"></h3><p x-text="correction.sub"></p></div></div>
                <div class="dlg-body">
                    <div class="opt-group"><span>{{ __('pos.corrections.reason') }} *</span>
                        <div class="opts" id="correction-reasons">
                            <template x-for="r in correctionReasons()" :key="r.id">
                                <button type="button" class="opt" :data-reason="r.id" :aria-pressed="correction.reasonId === r.id" x-on:click="correction.reasonId = r.id" x-text="r.label"></button>
                            </template>
                        </div>
                    </div>
                    <label class="opt-group" x-show="selectedReason()?.requires_note"><span>{{ __('pos.corrections.note_required') }} *</span>
                        <input class="input" id="correction-note" :maxlength="cfg.noteMax" x-model="correction.note">
                    </label>
                    <template x-if="correction.needsPin">
                        <div style="display: contents">
                            <span class="label">{{ __('pos.corrections.pin') }}</span>
                            <output class="keypad-display pin-display" id="correction-pin" x-text="correction.pin ? '•'.repeat(correction.pin.length) : '–'"></output>
                            <div class="keypad">
                                @foreach (['1', '2', '3', '4', '5', '6', '7', '8', '9'] as $k)<button type="button" data-c="{{ $k }}" x-on:click="pressCorrectionPin('{{ $k }}')">{{ $k }}</button>@endforeach
                                <button type="button" data-c="clear" x-on:click="correction.pin = ''">{{ __('pos.keypad.clear') }}</button>
                                <button type="button" data-c="0" x-on:click="pressCorrectionPin('0')">0</button>
                                <button type="button" data-c="back" aria-label="{{ __('pos.keypad.backspace') }}" x-on:click="correction.pin = correction.pin.slice(0, -1)">⌫</button>
                            </div>
                        </div>
                    </template>
                    <p class="pin-error" id="correction-error" x-show="correction.error" x-text="correction.error"></p>
                </div>
                <div class="dlg-foot">
                    <button class="btn" type="button" x-on:click="closeCorrection()">{{ __('pos.corrections.cancel') }}</button>
                    <button class="btn btn-primary" type="button" id="correction-ok" style="flex: 1" x-show="!correction.needsPin" :disabled="!correctionReady()" x-on:click="submitCorrection()">{{ __('pos.corrections.confirm') }}</button>
                </div>
            </div>
        </template>
    </dialog>

    @if ($shift)
        {{-- Ringkasan shift berjalan --}}
        <dialog id="shift-dlg" class="shift-dlg" wire:ignore.self x-ref="shiftDlg">
            <div class="dlg-head"><div><h3 id="shift-title">{{ __('pos.shift_info.title', ['name' => $shift->name, 'cashier' => $shift->opener?->name]) }}</h3>
                <p id="shift-sub">{{ __('pos.shift_info.sub', ['time' => $shift->opened_at->format('H:i'), 'float' => $rp($shift->opening_float)]) }}</p></div></div>
            <div class="dlg-body">
                <div class="stats" id="shift-stats">
                    <div class="stat"><span>{{ __('pos.shift_info.count') }}</span><b>{{ $shiftSummary['count'] }}</b></div>
                    @foreach ($shiftMethods as $m)<div class="stat"><span>{{ $m }}</span><b>{{ $rp($shiftSummary['by_method'][$m] ?? 0) }}</b></div>@endforeach
                    <div class="stat"><span>{{ __('pos.shift_info.cash_in_out') }}</span><b>+{{ number_format($shiftSummary['cash_in'], 0, ',', '.') }} / −{{ number_format($shiftSummary['cash_out'], 0, ',', '.') }}</b></div>
                </div>
                <p class="muted-note" id="shift-blind">{{ __('pos.shift_info.blind_note') }}</p>
                <div id="shift-cashlog">
                    @if ($cashMovements->isNotEmpty())
                        <div class="kv">
                            @foreach ($cashMovements as $c)
                                <div><span>{{ $c->created_at->format('H:i') }} · {{ $c->type === \App\Enums\CashMovementType::In ? __('pos.shift_info.in') : __('pos.shift_info.out') }} · {{ $c->reason_label }}@if ($c->approver) · {{ __('pos.shift_info.approved_by', ['name' => $c->approver->name]) }}@endif</span>
                                    <b>{{ $c->type === \App\Enums\CashMovementType::In ? '+' : '−' }}{{ $rp($c->amount) }}</b></div>
                            @endforeach
                        </div>
                    @else
                        <p class="muted-note">{{ __('pos.shift_info.no_cash') }}</p>
                    @endif
                </div>
            </div>
            <div class="dlg-foot" style="flex-wrap: wrap">
                <button class="btn" type="button" data-s="in" x-on:click="$refs.shiftDlg.close(); openKas('in')">{{ __('pos.shift_info.cash_in') }}</button>
                <button class="btn" type="button" data-s="out" x-on:click="$refs.shiftDlg.close(); openKas('out')">{{ __('pos.shift_info.cash_out') }}</button>
                <button class="btn btn-primary" type="button" data-s="close" id="shift-close" style="flex: 1"
                        x-on:click="$refs.shiftDlg.close(); startClosing({{ $priced['count'] && ! $order['order_id'] ? 'true' : 'false' }}, {{ $held->count() }}, {{ (int) $held->sum('total') }})">{{ __('pos.shift_info.close') }}</button>
                <button class="btn" type="button" data-s="back" style="flex-basis: 100%" x-on:click="$refs.shiftDlg.close()">{{ __('pos.shift_info.back') }}</button>
            </div>
        </dialog>
    @endif

    {{-- Kas masuk / keluar (uang kecil). Di atas batas → PIN penyetuju. --}}
    {{-- Simpan pelanggan baru (database pelanggan). --}}
    <dialog id="cust-dlg" wire:ignore x-ref="cust" x-on:close="cust = null">
        <template x-if="cust">
            <form method="dialog" x-on:submit.prevent="saveCustomer()" novalidate>
                <div class="dlg-head"><div><h3>{{ __('pos.customers.save_title') }}</h3><p>{{ __('pos.customers.save_sub') }}</p></div></div>
                <div class="dlg-body">
                    <label class="opt-group"><span>{{ __('pos.customers.name') }} *</span><input class="input" id="cust-name" maxlength="{{ \App\Support\Customers::NAME_MAX }}" required autocomplete="off" x-model="cust.name"></label>
                    @if ($askPhone)
                        <label class="opt-group"><span>{{ __('pos.customers.phone') }}</span><input class="input" id="cust-phone" maxlength="{{ \App\Support\Customers::PHONE_INPUT_MAX }}" inputmode="tel" autocomplete="off" placeholder="08…" x-model="cust.phone"></label>
                    @endif
                    <label class="opt-group"><span>{{ __('pos.customers.notes_hint') }}</span><input class="input" id="cust-notes" maxlength="{{ \App\Support\Customers::NOTES_MAX }}" autocomplete="off" x-model="cust.notes"></label>
                    <button type="button" class="switch-row" role="switch" id="cust-consent" :aria-checked="cust.consent" x-on:click="cust.consent = !cust.consent"><span>{{ __('pos.customers.consent') }}</span><span class="switch" aria-hidden="true"></span></button>
                    <p class="pin-error" id="cust-error" x-show="cust.error" x-text="cust.error"></p>
                </div>
                <div class="dlg-foot">
                    <button class="btn" type="button" x-on:click="$refs.cust.close()">{{ __('pos.customers.cancel') }}</button>
                    <button class="btn btn-primary" type="submit" id="cust-save" style="flex: 1" :disabled="cust.busy || !cust.name.trim()">{{ __('pos.customers.save') }}</button>
                </div>
            </form>
        </template>
    </dialog>

    {{-- Ganti PIN sendiri: PIN lama → PIN baru → ulangi (dicek & dibatasi di server). --}}
    <dialog id="pin-change-dlg" class="keypad-dlg" wire:ignore x-ref="pinChange" x-on:close="pinChange = null">
        <template x-if="pinChange">
            <div>
                <div class="dlg-head"><div><h3 id="pin-change-title">{{ __('pos.pin_change.title') }}</h3><p id="pin-change-step" x-text="pinChangeLabel()"></p></div></div>
                <div class="dlg-body">
                    <output class="keypad-display" id="pin-change-display" x-text="pinChange[pinChange.step] ? '•'.repeat(pinChange[pinChange.step].length) : '–'"></output>
                    <div class="keypad" id="pin-change-keypad">
                        @foreach (['1', '2', '3', '4', '5', '6', '7', '8', '9'] as $k)<button type="button" data-p="{{ $k }}" x-on:click="pressPinChange('{{ $k }}')">{{ $k }}</button>@endforeach
                        <button type="button" data-p="clear" x-on:click="pinChange[pinChange.step] = ''">{{ __('pos.keypad.clear') }}</button>
                        <button type="button" data-p="0" x-on:click="pressPinChange('0')">0</button>
                        <button type="button" data-p="back" aria-label="{{ __('pos.keypad.backspace') }}" x-on:click="pinChange[pinChange.step] = pinChange[pinChange.step].slice(0, -1)">⌫</button>
                    </div>
                    <p class="pin-error" id="pin-change-error" x-show="pinChange.error" x-text="pinChange.error"></p>
                </div>
                <div class="dlg-foot"><button class="btn" type="button" style="flex: 1" x-on:click="$refs.pinChange.close()">{{ __('pos.pin_change.cancel') }}</button></div>
            </div>
        </template>
    </dialog>

    <dialog id="kas-dlg" class="kas-dlg" wire:ignore x-ref="kas">
        <template x-if="kas">
            <div>
                <div class="dlg-head"><div><h3 id="kas-title" x-text="kas.type === 'in' ? @js(__('pos.cash.title_in')) : @js(__('pos.cash.title_out'))"></h3><p>{{ __('pos.cash.sub') }}</p></div></div>
                <div class="dlg-body">
                    <div class="seg" role="group" id="kas-kind">
                        <button type="button" data-kind="out" :aria-pressed="kas.type === 'out'" x-on:click="kas.type = 'out'; kas.reasonId = null; kas.pinMode = false">{{ __('pos.cash.title_out') }}</button>
                        <button type="button" data-kind="in" :aria-pressed="kas.type === 'in'" x-on:click="kas.type = 'in'; kas.reasonId = null; kas.pinMode = false">{{ __('pos.cash.title_in') }}</button>
                    </div>
                    <output class="keypad-display" id="kas-display" x-text="kas.pinMode ? (kas.pin ? '•'.repeat(kas.pin.length) : '–') : rp(Number(kas.value) || 0)"></output>
                    <template x-if="!kas.pinMode">
                        <div style="display: contents">
                            <div class="opts" id="kas-reasons">
                                <template x-for="r in cfg.cashReasons[kas.type]" :key="r.id">
                                    <button type="button" class="opt" :data-reason="r.id" :aria-pressed="kas.reasonId === r.id" x-on:click="kas.reasonId = r.id" x-text="r.label"></button>
                                </template>
                            </div>
                            <input class="input" id="kas-note" maxlength="40" :placeholder="kasReason()?.requires_note || !kas.reasonId ? @js(__('pos.cash.note_required')) : @js(__('pos.cash.note'))" x-model="kas.note">
                            <p class="muted-note" id="kas-hint" x-show="kasNeedsPin()" x-text="(Number(kas.value) || 0) > cfg.cashOutLimit ? @js(__('pos.cash.approval_hint', ['limit' => '__L__'])).replace('__L__', rp(cfg.cashOutLimit)) : @js(__('pos.cash.approval_hint_shift', ['limit' => '__L__'])).replace('__L__', rp(cfg.cashOutShiftLimit))"></p>
                            <div class="keypad" id="kas-keypad">
                                @foreach (['1', '2', '3', '4', '5', '6', '7', '8', '9', '000', '0'] as $k)<button type="button" data-k="{{ $k }}" x-on:click="kas.value = (kas.value + '{{ $k }}').replace(/^0+/, '').slice(0, 9)">{{ $k }}</button>@endforeach
                                <button type="button" data-k="back" aria-label="{{ __('pos.keypad.backspace') }}" x-on:click="kas.value = kas.value.slice(0, -1)">⌫</button>
                            </div>
                        </div>
                    </template>
                    <template x-if="kas.pinMode">
                        <div style="display: contents">
                            <span class="label">{{ __('pos.cash.pin') }}</span>
                            <div class="keypad" id="kas-pin">
                                @foreach (['1', '2', '3', '4', '5', '6', '7', '8', '9'] as $k)<button type="button" data-p="{{ $k }}" x-on:click="pressKasPin('{{ $k }}')">{{ $k }}</button>@endforeach
                                <button type="button" data-p="clear" x-on:click="kas.pin = ''">{{ __('pos.keypad.clear') }}</button>
                                <button type="button" data-p="0" x-on:click="pressKasPin('0')">0</button>
                                <button type="button" data-p="back" x-on:click="kas.pin = kas.pin.slice(0, -1)">⌫</button>
                            </div>
                        </div>
                    </template>
                    <p class="pin-error" id="kas-error" x-show="kas.error" x-text="kas.error"></p>
                </div>
                <div class="dlg-foot">
                    <button class="btn" type="button" id="kas-cancel" x-on:click="$refs.kas.close(); kas = null">{{ __('pos.cash.cancel') }}</button>
                    <button class="btn btn-primary" type="button" id="kas-ok" style="flex: 1" x-show="!kas.pinMode" :disabled="!kasReady()" x-on:click="saveKas()">{{ __('pos.cash.save') }}</button>
                </div>
            </div>
        </template>
    </dialog>

    {{-- Tutup shift: hitung buta → hasil & setoran → (tutup hari) cocokkan EDC → (bila perlu) PIN penyetuju. --}}
    <dialog id="close-dlg" class="shift-dlg" wire:ignore x-ref="close" x-on:cancel.prevent>
        <template x-if="closing">
            <div>
                <div class="dlg-head"><div><h3 id="close-title" x-text="closeTitle()"></h3><p id="close-sub" x-text="@js(__('pos.close.sub', ['name' => '__N__', 'cashier' => '__C__', 'time' => '__T__'])).replace('__N__', cfg.shift?.name).replace('__C__', cfg.shift?.cashier).replace('__T__', cfg.shift?.opened)"></p></div>
                    <span class="steps" id="close-steps" x-text="@js(__('pos.close.step', ['n' => '__N__', 'total' => '__T__'])).replace('__N__', closingSteps().indexOf(closing.step) + 1).replace('__T__', closingSteps().length)"></span></div>
                <div class="dlg-body" id="close-body">
                    <template x-if="closing.step === 'count'">
                        <div style="display: contents">
                            <p class="muted-note">{{ __('pos.close.count_hint') }}</p>
                            <div class="denoms" id="close-denoms">
                                <template x-for="d in cfg.denoms" :key="d">
                                    <div class="denom">
                                        <span class="lbl" x-text="d.toLocaleString(@js(str_replace('_', '-', app()->getLocale())))"></span>
                                        <button type="button" class="pm" :data-d="d" data-step="-1" x-on:click="closing.counts[d] = Math.max(0, (closing.counts[d] || 0) - 1)">−</button>
                                        <button type="button" class="n" :data-d="d" x-on:click="editCloseCount(d)" x-text="closing.counts[d] || 0"></button>
                                        <button type="button" class="pm" :data-d="d" data-step="1" x-on:click="closing.counts[d] = Math.min(999, (closing.counts[d] || 0) + 1); bangkuHaptic()">+</button>
                                    </div>
                                </template>
                                <div class="denom coins"><span class="lbl">{{ __('pos.shift.coins') }}<small>{{ __('pos.shift.coins_hint') }}</small></span>
                                    <button type="button" class="money-btn" data-coins x-on:click="editCloseCoins()" x-text="rp(closing.coins)"></button></div>
                            </div>
                            <div class="count-total"><span>{{ __('pos.close.in_drawer') }}</span><b id="close-total" x-text="rp(closeCounted())"></b></div>
                        </div>
                    </template>
                    <template x-if="closing.step === 'result' && closing.preview">
                        <div style="display: contents">
                            <div class="variance" id="close-variance" :class="closing.preview.variance === 0 ? 'ok' : (closing.preview.variance < 0 ? 'short' : 'over')">
                                <span x-text="closing.preview.variance === 0 ? @js(__('pos.close.ok')) : (closing.preview.variance < 0 ? @js(__('pos.close.short')) : @js(__('pos.close.over')))"></span><b x-text="signed(closing.preview.variance)"></b></div>
                            <div class="kv">
                                <div><span>{{ __('pos.close.float') }}</span><span x-text="rp(cfg.shift.float)"></span></div>
                                <div><span x-text="@js(__('pos.close.cash_sales', ['count' => '__C__'])).replace('__C__', closing.preview.cash_sales_count)"></span><span x-text="'+ ' + rp(closing.preview.summary.cash_sales)"></span></div>
                                <div x-show="closing.preview.summary.cash_refunds"><span>{{ __('pos.close.cash_refunds') }}</span><span x-text="'− ' + rp(closing.preview.summary.cash_refunds)"></span></div>
                                <div><span>{{ __('pos.close.cash_in') }}</span><span x-text="'+ ' + rp(closing.preview.summary.cash_in)"></span></div>
                                <div><span>{{ __('pos.close.cash_out') }}</span><span x-text="'− ' + rp(closing.preview.summary.cash_out)"></span></div>
                                <div class="total"><span>{{ __('pos.close.expected') }}</span><span id="close-expected" x-text="rp(closing.preview.summary.expected)"></span></div>
                                <div class="total"><span>{{ __('pos.close.counted') }}</span><span x-text="rp(closing.preview.counted)"></span></div>
                            </div>
                            <label class="opt-group" x-show="closing.preview.variance !== 0"><span x-text="@js(__('pos.close.variance_note')) + ' *' + (closing.preview.variance_needs_approval ? ' · ' + @js(__('pos.close.variance_approval', ['limit' => '__L__'])).replace('__L__', rp(cfg.varianceLimit)) : '')"></span>
                                <input class="input" id="close-reason" maxlength="60" placeholder="{{ __('pos.close.variance_placeholder') }}" x-model="closing.varianceNote"></label>
                            <p class="muted-note" id="close-openbills" x-show="closing.preview.open_bills.count" x-text="@js(__('pos.close.open_bills', ['count' => '__C__', 'total' => '__T__'])).replace('__C__', closing.preview.open_bills.count).replace('__T__', rp(closing.preview.open_bills.total)) + (closing.preview.carry_needs_approval ? @js(__('pos.close.open_bills_day')) : '')"></p>
                            <div class="split">
                                <div class="opt-group"><span>{{ __('pos.close.float_left') }}</span><button type="button" class="money-btn" id="close-float" x-on:click="editFloatLeft()" x-text="rp(closing.floatLeft)"></button></div>
                                <div class="opt-group"><span>{{ __('pos.close.deposit') }}</span><div class="money-out" id="close-deposit" x-text="rp(closing.preview.counted - closing.floatLeft)"></div></div>
                            </div>
                            <button type="button" class="switch-row" id="close-day" role="switch" :aria-checked="closing.dayClose" x-on:click="toggleDayClose()"><span>{{ __('pos.close.day_close') }}</span><span class="switch" aria-hidden="true"></span></button>
                            <p class="muted-note" x-show="closing.dayClose && closing.preview.edc_settled">{{ __('pos.close.day_settled') }}</p>
                        </div>
                    </template>
                    <template x-if="closing.step === 'edc' && closing.preview">
                        <div style="display: contents">
                            <p class="muted-note">{{ __('pos.close.edc_hint') }}</p>
                            <div class="edc-table">
                                <div class="edc-row head"><span>{{ __('pos.close.edc_method') }}</span><span>{{ __('pos.close.edc_pos') }}</span><span>{{ __('pos.close.edc_slip') }}</span></div>
                                <template x-for="row in closing.preview.edc" :key="row.label">
                                    <div class="edc-row"><b x-text="row.label"></b><span x-text="rp(row.pos_amount)"></span>
                                        <button type="button" class="money-btn" :class="{ 'empty-val': closing.edc[row.label] === undefined }" :data-edc="row.label" x-on:click="editEdc(row.label)" x-text="closing.edc[row.label] === undefined ? @js(__('pos.close.edc_fill')) : rp(closing.edc[row.label])"></button>
                                        <span class="diff" x-show="closing.edc[row.label] !== undefined" :class="closing.edc[row.label] === row.pos_amount ? 'good' : 'bad'"
                                              x-text="closing.edc[row.label] === row.pos_amount ? @js(__('pos.close.edc_match')) : @js(__('pos.close.edc_diff', ['amount' => '__A__'])).replace('__A__', signed(closing.edc[row.label] - row.pos_amount))"></span></div>
                                </template>
                            </div>
                            <label class="opt-group" x-show="edcHasDiff()"><span>{{ __('pos.close.edc_note') }} *</span><input class="input" id="close-edc-note" maxlength="60" placeholder="{{ __('pos.close.edc_note_placeholder') }}" x-model="closing.edcNote"></label>
                        </div>
                    </template>
                    <template x-if="closing.step === 'pin' && closing.preview">
                        <div style="display: contents">
                            <p class="muted-note" x-text="@js(__('pos.close.pin_hint', ['what' => '__W__'])).replace('__W__', pinReasons())"></p>
                            <output class="keypad-display pin-display" id="close-pin" x-text="closing.pin ? '•'.repeat(closing.pin.length) : '–'"></output>
                            <div class="keypad">
                                @foreach (['1', '2', '3', '4', '5', '6', '7', '8', '9'] as $k)<button type="button" data-p="{{ $k }}" x-on:click="pressClosePin('{{ $k }}')">{{ $k }}</button>@endforeach
                                <button type="button" data-p="clear" x-on:click="closing.pin = ''">{{ __('pos.keypad.clear') }}</button>
                                <button type="button" data-p="0" x-on:click="pressClosePin('0')">0</button>
                                <button type="button" data-p="back" x-on:click="closing.pin = closing.pin.slice(0, -1)">⌫</button>
                            </div>
                        </div>
                    </template>
                    <p class="pin-error" id="close-error" x-show="closing.error" x-text="closing.error"></p>
                </div>
                <div class="dlg-foot">
                    <button class="btn" type="button" id="close-back" x-on:click="closeBack()" x-text="closingSteps().indexOf(closing.step) === 0 ? @js(__('pos.close.cancel')) : @js(__('pos.close.back'))"></button>
                    <button class="btn btn-primary" type="button" id="close-next" style="flex: 1" x-show="closing.step !== 'pin'" :disabled="!closeStepReady()" x-on:click="closeNext()"
                            x-text="isLastCloseStep() ? @js(__('pos.close.finish')) : @js(__('pos.close.next'))"></button>
                </div>
            </div>
        </template>
    </dialog>

    {{-- Printer: cara cetak per tablet (Bluetooth LE / RawBT / dialog cetak). --}}
    <dialog id="printer-dlg" class="keypad-dlg" wire:ignore x-ref="printerDlg">
        <div class="dlg-head"><div><h3>{{ __('pos.printer.title') }}</h3><p>{{ __('pos.printer.sub', ['cols' => app(\App\Support\Printing\Documents::class)->cols()]) }}</p></div></div>
        <div class="dlg-body">
            <div class="opt-group"><span>{{ __('pos.printer.method') }}</span>
                <div class="opts" id="printer-methods">
                    <button type="button" class="opt" data-method="bluetooth" :aria-pressed="$store.printer.method === 'bluetooth'" x-on:click="$store.printer.setMethod('bluetooth')">{{ __('pos.printer.bluetooth') }}</button>
                    <button type="button" class="opt" data-method="rawbt" :aria-pressed="$store.printer.method === 'rawbt'" x-on:click="$store.printer.setMethod('rawbt')">{{ __('pos.printer.rawbt') }}</button>
                    <button type="button" class="opt" data-method="dialog" :aria-pressed="$store.printer.method === 'dialog'" x-on:click="$store.printer.setMethod('dialog')">{{ __('pos.printer.dialog') }}</button>
                </div>
            </div>
            <p class="print-note" x-show="$store.printer.method === 'bluetooth'">{{ __('pos.printer.bluetooth_hint') }}</p>
            <p class="print-note" x-show="$store.printer.method === 'bluetooth' && !$store.printer.hasBluetooth">{{ __('pos.printer.unsupported') }}</p>
            <p class="print-note" x-show="$store.printer.method === 'rawbt'">{{ __('pos.printer.rawbt_hint') }}</p>
            <p class="print-note" x-show="$store.printer.method === 'dialog'">{{ __('pos.printer.dialog_hint') }}</p>
        </div>
        <div class="dlg-foot" style="flex-wrap: wrap">
            <button class="btn" type="button" id="printer-connect" x-show="$store.printer.method === 'bluetooth'" x-on:click="$store.printer.connect()">{{ __('pos.printer.connect') }}</button>
            <button class="btn btn-primary" type="button" id="printer-test" style="flex: 1" x-on:click="$wire.testPrint()">{{ __('pos.printer.test') }}</button>
            <button class="btn" type="button" style="flex-basis: 100%" x-on:click="$refs.printerDlg.close()">{{ __('pos.printer.close') }}</button>
        </div>
    </dialog>

    {{-- Pratinjau & cetak manual (printer belum terhubung). --}}
    <dialog id="receipt-dlg" wire:ignore x-ref="receiptDlg">
        <div class="dlg-head"><div><h3>{{ __('pos.printer.preview_title') }}</h3><p x-text="@js(__('pos.printer.preview_sub', ['cols' => '__C__'])).replace('__C__', $store.printer.preview?.cols)"></p></div></div>
        <div class="dlg-body">
            <div class="receipt-wrap"><div class="receipt" id="receipt" :style="'--cols:' + ($store.printer.preview?.cols || 32)">
                <template x-for="(doc, d) in ($store.printer.preview?.docs || [])" :key="d">
                    <div><template x-for="(line, i) in doc.lines" :key="i"><div :class="line.style" x-text="line.text || ' '" style="white-space: pre"></div></template><div x-show="d < $store.printer.preview.docs.length - 1" style="color:#999">- - - - - - - - - - - - - - - -</div></div>
                </template>
            </div></div>
            <p class="print-note">{{ __('pos.printer.preview_note') }}</p>
        </div>
        <div class="dlg-foot" style="flex-wrap: wrap">
            <button class="btn" type="button" id="print-browser" x-on:click="$store.printer.dialog($store.printer.preview)">{{ __('pos.printer.dialog') }}</button>
            <button class="btn" type="button" id="print-rawbt" x-show="$store.printer.isAndroid" x-on:click="$store.printer.rawbt($store.printer.preview); $refs.receiptDlg.close()">{{ __('pos.printer.rawbt') }}</button>
            <button class="btn btn-primary" type="button" id="print-bt" x-show="$store.printer.hasBluetooth" x-on:click="$store.printer.printPreviewBluetooth()">{{ __('pos.printer.bluetooth') }}</button>
            <button class="btn" type="button" id="receipt-close" style="flex-basis: 100%" x-on:click="$refs.receiptDlg.close()">{{ __('pos.printer.close') }}</button>
        </div>
    </dialog>

    {{-- Area cetak untuk dialog cetak browser (lihat @media print di kasir.css). --}}
    <div id="print-area" wire:ignore x-data><div class="receipt" id="print-receipt" :style="'--cols:' + ($store.printer.preview?.cols || 32)">
        <template x-for="(doc, d) in ($store.printer.preview?.docs || [])" :key="d">
            <div><template x-for="(line, i) in doc.lines" :key="i"><div :class="line.style" x-text="line.text || ' '" style="white-space: pre"></div></template><br></div>
        </template>
    </div></div>

    <script>
        window.bangkuPrinterText = @js([
            'none' => __('pos.printer.none'), 'searching' => __('pos.printer.searching'), 'printing' => __('pos.printer.printing'),
            'disconnected' => __('pos.printer.disconnected'), 'failed' => __('pos.printer.failed'), 'noWrite' => __('pos.printer.no_write'),
            'unsupported' => __('pos.printer.unsupported'), 'rawbt' => __('pos.printer.rawbt_label'), 'dialog' => __('pos.printer.dialog_label'),
            'printed' => __('pos.printer.printed'),
        ]);
    </script>

    {{-- Keypad angka umum (jumlah lembar, koin, nominal) — keyboard Android tidak menutupi layar. --}}
    <dialog id="keypad-dlg" class="keypad-dlg" wire:ignore x-data x-on:cancel.prevent="$store.keypad.cancel()">
        <div class="dlg-head"><div><h3 x-text="$store.keypad.title"></h3></div></div>
        <div class="dlg-body">
            <output class="keypad-display" x-text="$store.keypad.display">–</output>
            <div class="keypad">
                @foreach (['1', '2', '3', '4', '5', '6', '7', '8', '9'] as $k)<button type="button" data-k="{{ $k }}" x-on:click="$store.keypad.press('{{ $k }}')">{{ $k }}</button>@endforeach
                <button type="button" data-k="clear" x-on:click="$store.keypad.press('clear')">{{ __('pos.keypad.clear') }}</button>
                <button type="button" data-k="0" x-on:click="$store.keypad.press('0')">0</button>
                <button type="button" data-k="back" aria-label="{{ __('pos.keypad.backspace') }}" x-on:click="$store.keypad.press('back')">⌫</button>
            </div>
        </div>
        <div class="dlg-foot"><button class="btn btn-primary" type="button" data-k="ok" style="flex: 1" x-on:click="$store.keypad.done()">{{ __('pos.keypad.done') }}</button></div>
    </dialog>

    <script>
        document.addEventListener('alpine:init', () => {
            const locale = @js(str_replace('_', '-', app()->getLocale()));
            window.rp = (n) => 'Rp ' + Math.round(n || 0).toLocaleString(locale);

            // Layar kasir: filter menu, dialog varian/diskon/PIN/konfirmasi (tampilan saja; semua dicek di server).
            Alpine.data('kasir', (cfg) => ({
                // ---- ganti PIN sendiri
                pinChange: null,
                openPinChange() { this.pinChange = { step: 'current', current: '', next: '', confirm: '', error: '', busy: false }; this.$nextTick(() => this.$refs.pinChange.showModal()); },
                pinChangeLength() { return this.pinChange.step === 'current' ? cfg.ownPinLength : cfg.pinLength; },
                pinChangeLabel() {
                    return { current: @js(__('pos.pin_change.current', ['length' => '__N__'])), next: @js(__('pos.pin_change.new', ['length' => '__N__'])), confirm: @js(__('pos.pin_change.confirm')) }[this.pinChange.step].replace('__N__', this.pinChangeLength());
                },
                pressPinChange(d) {
                    bangkuHaptic();
                    const c = this.pinChange; const step = c.step;
                    if (c.busy || c[step].length >= this.pinChangeLength()) return;
                    c[step] += d; c.error = '';
                    if (c[step].length < this.pinChangeLength()) return;
                    if (step === 'current') { c.step = 'next'; return; }
                    if (step === 'next') { c.step = 'confirm'; return; }
                    if (c.next !== c.confirm) { c.error = @js(__('pos.pin_change.errors.confirm')); c.confirm = ''; bangkuHaptic(60); return; }
                    c.busy = true;
                    this.$wire.changePin(c.current, c.next, c.confirm).finally(() => { if (this.pinChange) this.pinChange.busy = false; });
                },
                pinChangeFailed(e) {
                    if (!this.pinChange) return;
                    // PIN lama salah/terkunci → mulai dari PIN lama; PIN baru ditolak → ulangi PIN baru.
                    Object.assign(this.pinChange, e.restart ? { step: 'current', current: '', next: '', confirm: '' } : { step: 'next', next: '', confirm: '' }, { error: e.message });
                    bangkuHaptic(60);
                },
                // ---- pelanggan
                suggestions: [],
                cust: null,
                suggestTimer: null,
                suggestCustomers(q) {
                    clearTimeout(this.suggestTimer);
                    if (!cfg.customers || document.getElementById('cust-tag') || q.trim().length < 2) { this.suggestions = []; return; }
                    this.suggestTimer = setTimeout(async () => {
                        const list = (await this.$wire.searchCustomers(q)) || [];
                        // Hasil yang datang terlambat diabaikan bila kolom sudah ditinggal/diubah atau pelanggan sudah dipilih.
                        const input = document.getElementById('customer');
                        if (document.activeElement === input && input.value === q && !document.getElementById('cust-tag')) this.suggestions = list;
                    }, 200);
                },
                hideSuggestions() { clearTimeout(this.suggestTimer); this.suggestions = []; },
                customerLine(c) { return [c.phone || @js(__('pos.customers.no_phone')), @js(__('pos.customers.visits', ['count' => '__N__'])).replace('__N__', c.visits)].join(' · '); },
                pickCustomer(c) {
                    document.getElementById('customer').value = c.name;
                    document.getElementById('name-field').classList.remove('invalid');
                    this.hideSuggestions();
                    this.$wire.linkCustomer(c.id);
                },
                openCustomer() {
                    const v = document.getElementById('customer').value.trim();
                    const looksPhone = /^[+\d\s-]{6,}$/.test(v);
                    this.cust = { name: looksPhone ? '' : v, phone: looksPhone ? v : '', notes: '', consent: false, error: '', busy: false };
                    this.$nextTick(() => this.$refs.cust.showModal());
                },
                saveCustomer() {
                    const c = this.cust;
                    if (!c || c.busy || !c.name.trim()) return;
                    c.busy = true; c.error = '';
                    this.$wire.registerCustomer(c.name, c.phone, c.notes, c.consent).finally(() => { if (this.cust) this.cust.busy = false; });
                },
                // ---- kas masuk/keluar
                kas: null,
                openKas(type) { this.kas = { type, value: '', reasonId: null, note: '', pin: '', pinMode: false, error: '', busy: false }; this.$nextTick(() => this.$refs.kas.showModal()); },
                kasReason() { return cfg.cashReasons[this.kas.type].find((r) => r.id === this.kas.reasonId); },
                kasNeedsPin() { const v = Number(this.kas.value) || 0; return this.kas.type === 'out' && (v > cfg.cashOutLimit || (cfg.cashOutShiftLimit > 0 && cfg.shiftCashOut + v > cfg.cashOutShiftLimit)); },
                kasReady() { const k = this.kas; const r = this.kasReason(); return k && !k.busy && Number(k.value) > 0 && r && (!r.requires_note || k.note.trim()); },
                saveKas() { if (this.kasNeedsPin()) { this.kas.pinMode = true; this.kas.pin = ''; return; } this.submitKas(); },
                pressKasPin(d) { bangkuHaptic(); if (this.kas.pin.length >= cfg.pinLength) return; this.kas.pin += d; if (this.kas.pin.length === cfg.pinLength) this.submitKas(); },
                submitKas() {
                    const k = this.kas; k.busy = true; k.error = '';
                    this.$wire.addCash(k.type, Number(k.value) || 0, k.reasonId, k.note, k.pin).finally(() => { if (this.kas) this.kas.busy = false; });
                },
                kasFailed(e) { if (this.kas) { this.kas.error = e.message; this.kas.pin = ''; } bangkuHaptic(60); },
                // ---- tutup shift
                closing: null,
                signed(n) { return (n > 0 ? '+' : n < 0 ? '−' : '') + rp(Math.abs(n)); },
                async startClosing(unsaved, heldCount, heldTotal) {
                    if (unsaved) { this.$dispatch('toast', { message: @js(__('pos.close.draft_unsaved')) }); return; }
                    if (heldCount) {
                        if (!cfg.carryOpenBills) { this.$dispatch('toast', { message: @js(__('pos.close.open_bills_blocked', ['count' => '__C__'])).replace('__C__', heldCount) }); return; }
                        const text = @js(__('pos.close.open_bills_confirm', ['count' => '__C__', 'total' => '__T__'])).replace('__C__', heldCount).replace('__T__', rp(heldTotal));
                        if (!(await this.confirm(@js(__('pos.close.open_bills_confirm_title')), text, @js(__('pos.close.open_bills_confirm_ok'))))) return;
                    }
                    this.closing = { step: 'count', counts: {}, coins: 0, preview: null, floatLeft: 0, varianceNote: '', dayClose: !!cfg.shift?.lastShift, edc: {}, edcNote: '', pin: '', error: '', busy: false };
                    this.$nextTick(() => this.$refs.close.showModal());
                },
                closeCounted() { return cfg.denoms.reduce((n, d) => n + d * (this.closing.counts[d] || 0), 0) + this.closing.coins; },
                async editCloseCount(d) { const v = await Alpine.store('keypad').open(@js(__('pos.shift.sheets_keypad', ['value' => '__V__'])).replace('__V__', rp(d)), this.closing.counts[d] || '', { max: 3 }); if (v !== null) this.closing.counts[d] = Number(v) || 0; },
                async editCloseCoins() { const v = await Alpine.store('keypad').open(@js(__('pos.shift.coins_keypad')), this.closing.coins || '', { max: 7, money: true }); if (v !== null) this.closing.coins = Number(v) || 0; },
                async editFloatLeft() { const v = await Alpine.store('keypad').open(@js(__('pos.close.float_keypad')), this.closing.floatLeft || '', { max: 9, money: true }); if (v !== null) this.closing.floatLeft = Math.min(Number(v) || 0, this.closing.preview.counted); },
                async editEdc(label) { const v = await Alpine.store('keypad').open(@js(__('pos.close.edc_keypad', ['label' => '__L__'])).replace('__L__', label), this.closing.edc[label] ?? '', { max: 9, money: true }); if (v !== null) this.closing.edc[label] = Number(v) || 0; },
                edcHasDiff() { return (this.closing.preview?.edc || []).some((r) => this.closing.edc[r.label] !== undefined && this.closing.edc[r.label] !== r.pos_amount); },
                needsClosePin() { const p = this.closing.preview; return !!p && (p.variance_needs_approval || p.carry_needs_approval); },
                closingSteps() {
                    const c = this.closing; const s = ['count', 'result'];
                    if (c.dayClose && c.preview && !c.preview.edc_settled) s.push('edc');
                    if (this.needsClosePin()) s.push('pin');
                    return s;
                },
                isLastCloseStep() { const s = this.closingSteps(); return s.indexOf(this.closing.step) === s.length - 1 || (s[s.indexOf(this.closing.step) + 1] === 'pin'); },
                closeTitle() { return { count: @js(__('pos.close.title_count')), result: @js(__('pos.close.title_result')), edc: @js(__('pos.close.title_edc')), pin: @js(__('pos.close.title_pin')) }[this.closing.step]; },
                closeStepReady() {
                    const c = this.closing;
                    if (c.busy) return false;
                    if (c.step === 'result') return c.preview && (c.preview.variance === 0 || c.varianceNote.trim());
                    if (c.step === 'edc') return c.preview.edc.every((r) => c.edc[r.label] !== undefined) && (!this.edcHasDiff() || c.edcNote.trim());
                    return true;
                },
                async loadPreview() {
                    const c = this.closing;
                    c.busy = true;
                    const p = await this.$wire.closingPreview(c.counts, c.coins, c.dayClose);
                    c.busy = false;
                    if (!p) return false;
                    const first = !c.preview;
                    c.preview = p;
                    if (first) c.floatLeft = Math.min(cfg.shift.float, p.counted);
                    return true;
                },
                async toggleDayClose() { this.closing.dayClose = !this.closing.dayClose; await this.loadPreview(); },
                async closeNext() {
                    const c = this.closing; c.error = '';
                    if (c.step === 'count') { if (await this.loadPreview()) c.step = 'result'; return; }
                    const s = this.closingSteps(); const next = s[s.indexOf(c.step) + 1];
                    if (next) { c.step = next; c.pin = ''; return; }
                    this.submitClose();
                },
                closeBack() {
                    const s = this.closingSteps(); const i = s.indexOf(this.closing.step);
                    if (i <= 0) { this.$refs.close.close(); this.closing = null; return; }
                    this.closing.step = s[i - 1]; this.closing.error = '';
                },
                pinReasons() {
                    const p = this.closing.preview; const out = [];
                    if (p.variance_needs_approval) out.push(@js(__('pos.close.pin_variance', ['amount' => '__A__'])).replace('__A__', this.signed(p.variance)));
                    if (p.carry_needs_approval) out.push(@js(__('pos.close.pin_carry', ['count' => '__C__'])).replace('__C__', p.open_bills.count));
                    return out.join(' + ');
                },
                pressClosePin(d) { bangkuHaptic(); const c = this.closing; if (c.pin.length >= cfg.pinLength) return; c.pin += d; if (c.pin.length === cfg.pinLength) this.submitClose(); },
                submitClose() {
                    const c = this.closing; c.busy = true; c.error = '';
                    this.$wire.closeShift({ counts: c.counts, coins: c.coins, float_left: c.floatLeft, variance_note: c.varianceNote, day_close: c.dayClose, edc: c.edc, edc_note: c.edcNote }, c.pin)
                        .finally(() => { if (this.closing) this.closing.busy = false; });
                },
                closeFailed(e) { if (this.closing) { this.closing.error = e.message; this.closing.pin = ''; } bangkuHaptic(60); },
                // ---- koreksi (void item tercetak, refund, batal open bill)
                correction: null,
                startCorrection(e) { this.correction = { ...e, reasonId: null, note: '', pin: '', error: '', busy: false }; this.$nextTick(() => this.$refs.correction.showModal()); },
                correctionReasons() { return this.correction?.kind === 'refund' ? cfg.reasons.refund : cfg.reasons.void; },
                selectedReason() { return this.correctionReasons().find((r) => r.id === this.correction.reasonId); },
                correctionReady() { const c = this.correction; const r = this.selectedReason(); return c && !c.busy && r && (!r.requires_note || c.note.trim()); },
                pressCorrectionPin(k) {
                    bangkuHaptic();
                    const c = this.correction;
                    if (!this.selectedReason()) { c.error = @js(__('pos.corrections.errors.reason')); return; }
                    if (c.pin.length >= cfg.pinLength) return;
                    c.pin += k;
                    if (c.pin.length === cfg.pinLength) this.submitCorrection();
                },
                submitCorrection() {
                    const c = this.correction;
                    c.busy = true; c.error = '';
                    this.$wire.confirmCorrection(c.reasonId, c.note, c.pin).finally(() => { if (this.correction) { this.correction.busy = false; } });
                },
                correctionFailed(e) { if (this.correction) { this.correction.error = e.message; this.correction.pin = ''; } bangkuHaptic(60); },
                closeCorrection() { this.$refs.correction.close(); this.correction = null; this.$wire.cancelCorrection(); },
                // ---- simpan & pesanan tersimpan
                hold() {
                    if (cfg.requireName && !document.getElementById('customer').value.trim()) { this.nameRequired(); return; }
                    bangkuHaptic();
                    this.$wire.hold();
                },
                async openHeld(id, hasUnsaved) {
                    this.$refs.held.close();
                    if (hasUnsaved && !(await this.confirm(@js(__('pos.held.switch_title')), @js(__('pos.held.switch_text')), @js(__('pos.held.switch_ok'))))) return;
                    this.$wire.openHeld(id);
                },
                // ---- bayar
                cfg, method: cfg.methods[0]?.id ?? null, payState: null, changeInfo: { change: 0, received: 0, total: 0, message: '' },
                get currentMethod() { return cfg.methods.find((m) => m.id === this.method) || cfg.methods[0]; },
                openPay(total, count, sub) {
                    if (!count || !this.currentMethod) return;
                    if (cfg.requireName && !document.getElementById('customer').value.trim()) { this.nameRequired(); return; }
                    this.payState = { method: this.currentMethod, total, sub, received: '', bank: null, receipt: cfg.receiptDefault, error: '', busy: false, fresh: true };
                    this.$nextTick(() => this.$refs.pay.showModal());
                },
                payTitle() { const m = this.payState.method; if (m.qr) return @js(__('pos.pay.title_qr', ['method' => '__M__'])).replace('__M__', m.name); return (m.cash ? @js(__('pos.pay.title_cash', ['method' => '__M__'])) : @js(__('pos.pay.title_edc', ['method' => '__M__']))).replace('__M__', m.cash ? m.name.toLowerCase() : m.name); },
                quickAmounts() {
                    const t = this.payState.total; const up = (s) => Math.ceil(t / s) * s;
                    return [...new Set([t, up(10000), up(50000), up(100000), up(100000) + 100000])].filter((v, i) => i === 0 || v > t).slice(0, 4);
                },
                changeDue() { return (Number(this.payState.received) || 0) - this.payState.total; },
                pressCash(k) {
                    bangkuHaptic();
                    const p = this.payState;
                    if (k === 'back') p.received = p.received.slice(0, -1);
                    else if ((p.received + k).length <= String(cfg.maxReceived).length) p.received = (p.received + k).replace(/^0+(?=\d)/, '');
                },
                canPay() { const p = this.payState; return p && !p.busy && (p.method.cash ? this.changeDue() >= 0 : (!p.method.bank || p.bank)); },
                submitPay() {
                    const p = this.payState;
                    p.busy = true; p.error = '';
                    this.$wire.pay(p.method.id, p.bank, Number(p.received) || 0, p.receipt).finally(() => { if (this.payState) this.payState.busy = false; });
                },
                paid(e) {
                    this.$refs.pay.close();
                    // Tunai: pratinjau/dialog cetak ditunda sampai layar kembalian ditutup (angka kembalian tidak tertutup).
                    Alpine.store('printer').print(e.job, { defer: e.cash });
                    if (e.cash) { this.changeInfo = e; this.$refs.change.showModal(); }
                    else { this.orderOpen = false; this.toastReprint(e); }
                },
                toastReprint(e) { this.$dispatch('toast', { message: e.message, action: { label: @js(__('pos.printer.reprint')), event: 'reprint', detail: { orderId: e.orderId } } }); },
                payFailed(e) { if (this.payState) this.payState.error = e.message; else this.$dispatch('toast', { message: e.message }); bangkuHaptic(60); },
                nameRequired() {
                    const f = document.getElementById('name-field');
                    if (this.payState) this.$refs.pay.close();
                    this.orderOpen = true; f.classList.remove('invalid'); void f.offsetWidth; f.classList.add('invalid');
                    document.getElementById('customer').focus(); bangkuHaptic(30);
                },
                cat: '', q: '', orderOpen: false, editing: null, discountInput: '', pin: '', approvalSub: '', approvalError: '', idle: null,
                confirmText: { title: '', text: '', ok: '' }, confirmResolve: null,
                resetIdle() { clearTimeout(this.idle); if (cfg.autoLock > 0) this.idle = setTimeout(() => this.$refs.lock.submit(), cfg.autoLock * 60000); },
                visible(catId, name) { return (this.cat === '' || this.cat === catId) && (!this.q.trim() || name.includes(this.q.trim().toLowerCase())); },
                get noResults() { const q = this.q.trim().toLowerCase(); return !Object.entries(cfg.menu).some(([, m]) => (this.cat === '' || this.cat === m.category) && (!q || m.name.toLowerCase().includes(q))); },
                pick(itemId, line = null) {
                    const item = cfg.menu[itemId];
                    if (!item) return;
                    bangkuHaptic();
                    if (!item.groups.length && line === null) { this.$wire.addLine(itemId, {}, 1, ''); return; }
                    const picks = Object.fromEntries(item.groups.map((g) => [g.id, line ? (line.picks[g.id] ?? g.default) : g.default]));
                    this.editing = { itemId, item, index: line ? line.index : null, picks, qty: line ? line.qty : 1, note: line ? line.note : '' };
                    this.$nextTick(() => this.$refs.opt.showModal());
                },
                optionTotal() {
                    if (!this.editing) return 0;
                    const e = this.editing;
                    return (e.item.price + e.item.groups.reduce((n, g) => n + ((g.options.find((o) => o.id === e.picks[g.id]) || {}).price || 0), 0)) * e.qty;
                },
                saveOptions() {
                    const e = this.editing;
                    if (e.index === null) this.$wire.addLine(e.itemId, e.picks, e.qty, e.note);
                    else this.$wire.updateLine(e.index, e.picks, e.qty, e.note);
                    this.$refs.opt.close();
                },
                async editPoints(title, current) { const v = await Alpine.store('keypad').open(title, String(current || ''), { max: 7 }); if (v !== null) this.$wire.redeemPoints(Number(v) || 0); },
                async editTable(title, current) { const v = await Alpine.store('keypad').open(title, current, { max: cfg.tableMax }); if (v !== null) this.$wire.setTable(v); },
                openDiscount(d) { this.discountInput = d.type === 'percent' ? d.value + '%' : (d.type === 'amount' ? String(d.value) : ''); this.$refs.disc.showModal(); },
                applyDiscount() {
                    const v = this.discountInput.trim();
                    const n = Math.round(Number(v.replace(/[^\d]/g, ''))) || 0;
                    this.$refs.disc.close();
                    this.$wire.applyDiscount(n ? (v.includes('%') ? 'percent' : 'amount') : null, n);
                },
                pressPin(k) {
                    bangkuHaptic();
                    if (this.pin.length >= cfg.pinLength) return;
                    this.pin += k;
                    if (this.pin.length === cfg.pinLength) this.$wire.approve(this.pin);
                },
                confirm(title, text, ok) { this.confirmText = { title, text, ok }; this.$refs.confirm.returnValue = ''; this.$refs.confirm.showModal(); return new Promise((r) => { this.confirmResolve = r; }); },
                confirmDone(ok) { const r = this.confirmResolve; this.confirmResolve = null; r && r(ok); },
            }));

            Alpine.store('keypad', {
                title: '', value: '', max: 3, money: false, fresh: true, resolve: null,
                get display() { return this.value === '' ? '–' : (this.money ? rp(Number(this.value)) : this.value); },
                open(title, value, { max = 3, money = false } = {}) {
                    Object.assign(this, { title, value: String(value || ''), max, money, fresh: true });
                    document.getElementById('keypad-dlg').showModal();
                    return new Promise((resolve) => { this.resolve = resolve; });
                },
                press(k) {
                    bangkuHaptic();
                    if (k === 'clear') this.value = '';
                    else if (k === 'back') this.value = this.value.slice(0, -1);
                    else { if (this.fresh) this.value = ''; if (this.value.length < this.max) this.value = (this.value + k).replace(/^0+(?=\d)/, ''); }
                    this.fresh = false;
                },
                finish(result) { const r = this.resolve; this.resolve = null; document.getElementById('keypad-dlg').close(); r && r(result); },
                done() { this.finish(this.value); },
                cancel() { this.finish(null); },
            });

            // Buka shift: hitungan di browser hanya untuk tampilan; server menghitung ulang (ShiftService).
            Alpine.data('openShift', (cfg) => ({
                template: cfg.suggested, counts: {}, coins: 0, note: '', busy: false,
                get total() { return cfg.denoms.reduce((n, d) => n + d * (this.counts[d] || 0), 0) + this.coins; },
                get diff() { return cfg.handover ? this.total - cfg.handover.amount : 0; },
                diffText(less, more) { return this.diff === 0 ? '' : (this.diff < 0 ? less : more).replace('__A__', rp(Math.abs(this.diff))); },
                step(d, s) { bangkuHaptic(); this.counts[d] = Math.max(0, Math.min({{ \App\Support\ShiftService::MAX_QUANTITY }}, (this.counts[d] || 0) + s)); },
                async editCount(d, title) { const v = await Alpine.store('keypad').open(title, this.counts[d] || '', { max: 3 }); if (v !== null) this.counts[d] = Number(v) || 0; },
                async editCoins(title) { const v = await Alpine.store('keypad').open(title, this.coins || '', { max: 7, money: true }); if (v !== null) this.coins = Number(v) || 0; },
                submit() { this.busy = true; this.$wire.openShift(this.template, this.counts, this.coins, this.note).finally(() => { this.busy = false; }); },
            }));
        });
    </script>
</div>
