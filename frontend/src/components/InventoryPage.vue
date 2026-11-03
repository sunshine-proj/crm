<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { getOrganizationDefaultCurrency } from "../lib/organizationSettings";
import { isFinanceToken } from "../lib/session";
import InventoryValuationPanel from "./InventoryValuationPanel.vue";

type Warehouse = { id: number; code: string; name: string; address: string | null; active: boolean };
type Supplier = { id: number; code: string; name: string; email: string | null; phone: string | null; address: string | null; active: boolean };
type Product = { id: number; sku: string; name: string; currency: string; unit_price: string; active: boolean };
type StockBalance = { product_id: number; sku: string; product_name: string; warehouse_id: number; warehouse_code: string; warehouse_name: string; quantity_on_hand: number };
type Movement = { id: number; product_id: number; sku: string; product_name: string; warehouse_id: number; warehouse_code: string; movement_type: string; quantity_delta: number; source_type: string; source_id: number | null; moved_at: string; memo: string };
type PurchaseLine = { product_id: number; sku: string; description: string; quantity: number; unit_cost_minor: number; line_total_minor: number; unit_cost: string; line_total: string; received_quantity: number };
type PurchaseOrder = { created_by?: string | null; id: number; order_number: string; supplier_id: number; supplier_name: string; currency: string; items: PurchaseLine[]; total: string; status: string; ordered_at: string };
type SalesShipment = { shipment_id: number; sales_order_id: number; order_number: string; client_name: string; shipped_at: string; reference: string | null; items: { product_id: number; description: string; quantity: number }[] };
type InventoryReturn = { id: number; return_type: string; source_type: string; source_id: number; warehouse_code: string; reference: string | null; returned_at: string; items: { product_id: number; description: string; quantity: number }[] };

const canSeeFinance = isFinanceToken(localStorage.getItem("crm_token"));
const tabs = (canSeeFinance ? ["Warehouses", "Stock", "Purchasing", "Returns", "Valuation"] : ["Warehouses", "Stock", "Purchasing", "Returns"]) as readonly ("Warehouses" | "Stock" | "Purchasing" | "Returns" | "Valuation")[];
type Tab = "Warehouses" | "Stock" | "Purchasing" | "Returns" | "Valuation";
const tab = ref<Tab>("Warehouses");
const warehouses = ref<Warehouse[]>([]);
const suppliers = ref<Supplier[]>([]);
const products = ref<Product[]>([]);
const balances = ref<StockBalance[]>([]);
const movements = ref<Movement[]>([]);
const orders = ref<PurchaseOrder[]>([]);
const pendingShipments = ref<SalesShipment[]>([]);
const dispatchedShipments = ref<SalesShipment[]>([]);
const returns = ref<InventoryReturn[]>([]);
const currencies = ["EUR", "GBP", "USD"];
const isLoading = ref(true);
const isSaving = ref(false);
const errorMessage = ref("");
const noticeMessage = ref("");
const warehouseCode = ref("");
const warehouseName = ref("");
const warehouseAddress = ref("");
const supplierCode = ref("");
const supplierName = ref("");
const supplierEmail = ref("");
const supplierPhone = ref("");
const supplierAddress = ref("");
const purchaseSupplierId = ref<number | null>(null);
const purchaseCurrency = ref(getOrganizationDefaultCurrency());
const purchaseDate = ref("");
const purchaseLines = ref<{ product_id: number | null; quantity: number; unit_cost: string }[]>([]);
const receiptOrderId = ref<number | null>(null);
const receiptWarehouseId = ref<number | null>(null);
const receiptDate = ref("");
const receiptReference = ref("");
const receiptLandedCost = ref("");
const adjustmentUnitCost = ref("");
const receiptQuantities = ref<Record<number, string>>({});
const dispatchWarehouseIds = ref<Record<number, number | null>>({});
const adjustmentProductId = ref<number | null>(null);
const adjustmentWarehouseId = ref<number | null>(null);
const adjustmentDelta = ref("");
const adjustmentReason = ref("");
const adjustmentDate = ref("");
const returnType = ref<"customer" | "supplier">("customer");
const returnSourceId = ref<number | null>(null);
const returnWarehouseId = ref<number | null>(null);
const returnDate = ref("");
const returnReference = ref("");
const returnLines = ref<{ product_id: number | null; quantity: number }[]>([]);

const activeWarehouses = computed(() => warehouses.value.filter((warehouse) => warehouse.active));
const activeSuppliers = computed(() => suppliers.value.filter((supplier) => supplier.active));
const selectedPurchaseOrder = computed(() => orders.value.find((order) => order.id === receiptOrderId.value) ?? null);
const returnSourceOptions = computed(() => returnType.value === "customer" ? dispatchedShipments.value : orders.value.filter((order) => order.items.some((line) => line.received_quantity > 0)));
const selectedReturnSource = computed(() => returnType.value === "customer"
  ? dispatchedShipments.value.find((shipment) => shipment.shipment_id === returnSourceId.value) ?? null
  : orders.value.find((order) => order.id === returnSourceId.value) ?? null);
const returnableLines = computed(() => {
  if (!selectedReturnSource.value) return [];
  if (returnType.value === "customer") return (selectedReturnSource.value as SalesShipment).items;
  return (selectedReturnSource.value as PurchaseOrder).items.filter((line) => line.received_quantity > 0).map((line) => ({ product_id: line.product_id, description: line.description, quantity: line.received_quantity }));
});

function parseMinor(value: string): bigint | null {
  const match = /^(0|[1-9]\d*)(?:\.(\d{1,2}))?$/.exec(value);
  if (!match) return null;
  return BigInt(match[1]) * 100n + BigInt((match[2] ?? "").padEnd(2, "0"));
}

function formatMinor(value: bigint): string {
  return `${value / 100n}.${String(value % 100n).padStart(2, "0")}`;
}

async function request<T>(path: string, options: RequestInit = {}): Promise<T> {
  const token = localStorage.getItem("crm_token");
  const response = await fetch(`/api${path}`, {
    ...options,
    headers: { "Content-Type": "application/json", ...(token ? { Authorization: `Bearer ${token}` } : {}), ...options.headers },
  });
  if (!response.ok) {
    const payload = (await response.json().catch(() => null)) as { error?: string } | null;
    throw new Error(payload?.error ?? "Unable to complete the inventory request.");
  }
  return response.status === 204 ? (undefined as T) : ((await response.json()) as T);
}

async function loadPage() {
  isLoading.value = true;
  errorMessage.value = "";
  try {
    const [loadedWarehouses, loadedSuppliers, loadedProducts, loadedBalances, loadedMovements, loadedOrders, pending, dispatched, loadedReturns] = await Promise.all([
      request<Warehouse[]>("/inventory/warehouses"),
      request<Supplier[]>("/inventory/suppliers"),
      request<Product[]>("/products"),
      request<StockBalance[]>("/inventory/stock"),
      request<Movement[]>("/inventory/movements"),
      request<PurchaseOrder[]>("/inventory/purchase-orders"),
      request<SalesShipment[]>("/inventory/sales-shipments/pending"),
      request<SalesShipment[]>("/inventory/sales-shipments/dispatched"),
      request<InventoryReturn[]>("/inventory/returns"),
    ]);
    warehouses.value = loadedWarehouses;
    suppliers.value = loadedSuppliers;
    products.value = loadedProducts;
    balances.value = loadedBalances;
    movements.value = loadedMovements;
    orders.value = loadedOrders;
    pendingShipments.value = pending;
    dispatchedShipments.value = dispatched;
    returns.value = loadedReturns;
    if (adjustmentProductId.value === null) adjustmentProductId.value = products.value[0]?.id ?? null;
    if (adjustmentWarehouseId.value === null) adjustmentWarehouseId.value = activeWarehouses.value[0]?.id ?? null;
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : "Unable to load inventory records.";
  } finally {
    isLoading.value = false;
  }
}

onMounted(loadPage);

async function submit<T>(path: string, payload: unknown, success: string, method = "POST"): Promise<T | null> {
  isSaving.value = true;
  errorMessage.value = "";
  noticeMessage.value = "";
  try {
    const result = await request<T>(path, { method, body: JSON.stringify(payload) });
    noticeMessage.value = success;
    return result;
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : "Unable to save inventory record.";
    return null;
  } finally {
    isSaving.value = false;
  }
}

async function createWarehouse() {
  const warehouse = await submit<Warehouse>("/inventory/warehouses", { code: warehouseCode.value, name: warehouseName.value, address: warehouseAddress.value || null }, "Warehouse added.");
  if (warehouse) {
    warehouses.value.push(warehouse);
    warehouseCode.value = "";
    warehouseName.value = "";
    warehouseAddress.value = "";
    if (adjustmentWarehouseId.value === null) adjustmentWarehouseId.value = warehouse.id;
  }
}

async function toggleWarehouse(warehouse: Warehouse) {
  const updated = await submit<Warehouse>(`/inventory/warehouses/${warehouse.id}`, {
    code: warehouse.code,
    name: warehouse.name,
    address: warehouse.address,
    active: !warehouse.active,
  }, `Warehouse ${warehouse.active ? "deactivated" : "activated"}.`, "PUT");
  if (updated) warehouses.value = warehouses.value.map((item) => item.id === updated.id ? updated : item);
}

async function createSupplier() {
  const supplier = await submit<Supplier>("/inventory/suppliers", { code: supplierCode.value, name: supplierName.value, email: supplierEmail.value || null, phone: supplierPhone.value || null, address: supplierAddress.value || null }, "Supplier added.");
  if (supplier) {
    suppliers.value.push(supplier);
    suppliers.value.sort((left, right) => left.name.localeCompare(right.name));
    supplierCode.value = "";
    supplierName.value = "";
    supplierEmail.value = "";
    supplierPhone.value = "";
    supplierAddress.value = "";
  }
}

async function toggleSupplier(supplier: Supplier) {
  const updated = await submit<Supplier>(`/inventory/suppliers/${supplier.id}`, {
    code: supplier.code,
    name: supplier.name,
    email: supplier.email,
    phone: supplier.phone,
    address: supplier.address,
    active: !supplier.active,
  }, `Supplier ${supplier.active ? "deactivated" : "activated"}.`, "PUT");
  if (updated) suppliers.value = suppliers.value.map((item) => item.id === updated.id ? updated : item);
}

function startPurchaseOrder() {
  purchaseSupplierId.value = activeSuppliers.value[0]?.id ?? null;
  purchaseCurrency.value = getOrganizationDefaultCurrency();
  purchaseDate.value = new Date().toISOString().slice(0, 10);
  purchaseLines.value = [{ product_id: null, quantity: 1, unit_cost: "" }];
}

function addPurchaseLine() {
  purchaseLines.value.push({ product_id: null, quantity: 1, unit_cost: "" });
}

const purchaseTotal = computed(() => purchaseLines.value.reduce((sum, line) => {
  const amount = parseMinor(line.unit_cost);
  return amount === null ? sum : sum + amount * BigInt(Math.max(0, line.quantity));
}, 0n));

async function createPurchaseOrder() {
  if (purchaseSupplierId.value === null || !purchaseLines.value.length || purchaseLines.value.some((line) => line.product_id === null || !Number.isInteger(line.quantity) || line.quantity < 1 || parseMinor(line.unit_cost) === null)) {
    errorMessage.value = "Select a supplier and enter a product, positive quantity, and unit cost for each line.";
    return;
  }
  const order = await submit<PurchaseOrder>("/inventory/purchase-orders", {
    supplier_id: purchaseSupplierId.value,
    currency: purchaseCurrency.value,
    ordered_at: purchaseDate.value,
    items: purchaseLines.value.map((line) => ({ product_id: line.product_id, quantity: line.quantity, unit_cost: line.unit_cost })),
  }, "Purchase order created.");
  if (order) {
    orders.value.unshift(order);
    purchaseLines.value = [];
  }
}

function openReceipt(order: PurchaseOrder) {
  receiptOrderId.value = order.id;
  receiptWarehouseId.value = activeWarehouses.value[0]?.id ?? null;
  receiptDate.value = new Date().toISOString().slice(0, 10);
  receiptReference.value = "";
  receiptLandedCost.value = "";
  receiptQuantities.value = Object.fromEntries(order.items.filter((line) => line.received_quantity < line.quantity).map((line) => [line.product_id, String(line.quantity - line.received_quantity)]));
}

async function receivePurchaseOrder(order: PurchaseOrder) {
  const items = order.items.map((line) => ({ product_id: line.product_id, quantity: Number(receiptQuantities.value[line.product_id] || 0) })).filter((line) => line.quantity > 0);
  if (receiptWarehouseId.value === null || !items.length) {
    errorMessage.value = "Choose a warehouse and enter a quantity for at least one item.";
    return;
  }
  const receipt = await submit("/inventory/purchase-orders/" + order.id + "/receipts", { warehouse_id: receiptWarehouseId.value, received_at: receiptDate.value, reference: receiptReference.value || null, items, ...(canSeeFinance && receiptLandedCost.value.trim() ? { landed_costs: [{ description: "Freight, duty and handling", amount: receiptLandedCost.value.trim() }] } : {}) }, `Goods received against ${order.order_number}.`);
  if (receipt) {
    receiptOrderId.value = null;
    await loadPage();
  }
}

async function decideOrder(order: PurchaseOrder, decision: "approve" | "reject") {
  let comment: string | null = null;
  if (decision === "reject") {
    comment = window.prompt("Why is this purchase order rejected?");
    if (!comment?.trim()) return;
  }
  const result = await submit<{ status: string }>(`/inventory/purchase-orders/${order.id}/${decision}`, { comment }, decision === "approve" ? `${order.order_number} approved.` : `${order.order_number} rejected.`);
  if (result) order.status = result.status;
}

async function createAdjustment() {
  const quantity = Number(adjustmentDelta.value);
  if (adjustmentProductId.value === null || adjustmentWarehouseId.value === null || !Number.isSafeInteger(quantity) || quantity === 0 || !adjustmentReason.value.trim()) {
    errorMessage.value = "Select product and warehouse, enter a non-zero whole quantity, and provide a reason.";
    return;
  }
  const result = await submit("/inventory/adjustments", { product_id: adjustmentProductId.value, warehouse_id: adjustmentWarehouseId.value, quantity_delta: quantity, reason: adjustmentReason.value, ...(canSeeFinance && adjustmentUnitCost.value.trim() ? { unit_cost: adjustmentUnitCost.value.trim(), currency: getOrganizationDefaultCurrency() } : {}), moved_at: adjustmentDate.value || new Date().toISOString().slice(0, 10) }, "Stock adjustment posted.");
  if (result) {
    adjustmentDelta.value = "";
    adjustmentReason.value = "";
    await loadPage();
  }
}

async function dispatchShipment(shipment: SalesShipment) {
  const warehouseId = dispatchWarehouseIds.value[shipment.shipment_id];
  if (warehouseId === undefined || warehouseId === null) {
    errorMessage.value = "Choose a warehouse before dispatching.";
    return;
  }
  const result = await submit(`/inventory/sales-shipments/${shipment.shipment_id}/dispatch`, { warehouse_id: warehouseId }, `${shipment.order_number} dispatched from inventory.`);
  if (result) await loadPage();
}

function changeReturnType() {
  returnSourceId.value = null;
  returnLines.value = [{ product_id: null, quantity: 1 }];
}

function changeReturnSource() {
  const source = selectedReturnSource.value;
  const lines = source === null ? [] : returnType.value === "customer"
    ? (source as SalesShipment).items
    : (source as PurchaseOrder).items.filter((line) => line.received_quantity > 0).map((line) => ({ product_id: line.product_id, description: line.description, quantity: line.received_quantity }));
  returnLines.value = lines.map((line) => ({ product_id: line.product_id, quantity: 1 }));
}

async function createReturn() {
  if (returnSourceId.value === null || returnWarehouseId.value === null || returnLines.value.length === 0 || returnLines.value.some((line) => line.product_id === null || !Number.isInteger(line.quantity) || line.quantity < 1)) {
    errorMessage.value = "Choose a source document and warehouse, then enter positive return quantities.";
    return;
  }
  const result = await submit<InventoryReturn>("/inventory/returns", {
    return_type: returnType.value,
    source_type: returnType.value === "customer" ? "sales_shipment" : "purchase_order",
    source_id: returnSourceId.value,
    warehouse_id: returnWarehouseId.value,
    returned_at: returnDate.value || new Date().toISOString().slice(0, 10),
    reference: returnReference.value || null,
    items: returnLines.value.map((line) => ({ product_id: line.product_id, quantity: line.quantity })),
  }, `${returnType.value === "customer" ? "Customer" : "Supplier"} return recorded.`);
  if (result) {
    returnSourceId.value = null;
    returnLines.value = [];
    returnReference.value = "";
    await loadPage();
  }
}
</script>

<template>
  <div class="page-content inventory-page">
    <div class="page-heading"><div><p class="eyebrow">Inventory and purchasing</p><h1>Track what arrives, moves, and leaves.</h1></div></div>
    <nav class="sales-tabs inventory-tabs" aria-label="Inventory sections"><button v-for="item in tabs" :key="item" type="button" :class="{ active: tab === item }" :aria-pressed="tab === item" @click="tab = item; errorMessage = ''; noticeMessage = ''">{{ item }}</button></nav>
    <p v-if="errorMessage" class="error" role="alert">{{ errorMessage }}</p><p v-if="noticeMessage" class="sales-notice" role="status">{{ noticeMessage }}</p>
    <div v-if="isLoading" class="empty-page"><h2>Loading inventory...</h2></div>

    <template v-else-if="tab === 'Warehouses'">
      <section class="inventory-section"><div class="inventory-section-heading"><div><p class="card-kicker">Warehouse locations</p><h2>Storage sites</h2></div></div>
        <form class="inventory-form-grid" @submit.prevent="createWarehouse"><div><label for="warehouse-code">Code</label><input id="warehouse-code" v-model="warehouseCode" maxlength="32" required /></div><div><label for="warehouse-name">Name</label><input id="warehouse-name" v-model="warehouseName" maxlength="160" required /></div><div><label for="warehouse-address">Address</label><input id="warehouse-address" v-model="warehouseAddress" /></div><button class="edit-button" type="submit" :disabled="isSaving">Add warehouse</button></form>
        <div v-if="warehouses.length" class="article-list"><article v-for="warehouse in warehouses" :key="warehouse.id" class="article-item inventory-row"><div><p class="card-kicker">{{ warehouse.code }} · {{ warehouse.active ? "Active" : "Inactive" }}</p><h2>{{ warehouse.name }}</h2><p v-if="warehouse.address" class="muted">{{ warehouse.address }}</p></div><button class="edit-button" type="button" :disabled="isSaving" @click="toggleWarehouse(warehouse)">{{ warehouse.active ? "Deactivate" : "Activate" }}</button></article></div><p v-else class="muted">No warehouse locations yet.</p>
      </section>
      <section class="inventory-section"><div class="inventory-section-heading"><div><p class="card-kicker">Supplier directory</p><h2>Vendors</h2></div></div>
        <form class="inventory-form-grid" @submit.prevent="createSupplier"><div><label for="supplier-code">Supplier code</label><input id="supplier-code" v-model="supplierCode" maxlength="32" required /></div><div><label for="supplier-name">Name</label><input id="supplier-name" v-model="supplierName" maxlength="255" required /></div><div><label for="supplier-email">Email</label><input id="supplier-email" v-model="supplierEmail" type="email" /></div><div><label for="supplier-phone">Phone</label><input id="supplier-phone" v-model="supplierPhone" maxlength="80" /></div><div><label for="supplier-address">Address</label><input id="supplier-address" v-model="supplierAddress" /></div><button class="edit-button" type="submit" :disabled="isSaving">Add supplier</button></form>
        <div v-if="suppliers.length" class="inventory-supplier-list"><article v-for="supplier in suppliers" :key="supplier.id"><div><strong>{{ supplier.code }} · {{ supplier.name }}</strong><span>{{ supplier.email ?? "No email" }}<span v-if="supplier.phone"> · {{ supplier.phone }}</span></span></div><button class="edit-button" type="button" :disabled="isSaving" @click="toggleSupplier(supplier)">{{ supplier.active ? "Deactivate" : "Activate" }}</button></article></div><p v-else class="muted">No suppliers yet.</p>
      </section>
    </template>

    <template v-else-if="tab === 'Stock'">
      <section class="inventory-section"><div class="inventory-section-heading"><div><p class="card-kicker">Stock on hand</p><h2>Current balances by warehouse</h2></div></div>
        <div v-if="balances.length" class="finance-table-wrap"><table class="finance-table"><thead><tr><th>SKU</th><th>Product</th><th>Warehouse</th><th>On hand</th></tr></thead><tbody><tr v-for="row in balances" :key="`${row.product_id}-${row.warehouse_id}`"><td>{{ row.sku }}</td><td>{{ row.product_name }}</td><td>{{ row.warehouse_code }} · {{ row.warehouse_name }}</td><td>{{ row.quantity_on_hand }}</td></tr></tbody></table></div><p v-else class="muted">No stock recorded. Receive a purchase order or post an opening adjustment.</p>
      </section>
      <section class="inventory-section"><div class="inventory-section-heading"><div><p class="card-kicker">Inventory adjustment</p><h2>Correct a counted quantity</h2></div></div>
        <form class="inventory-form-grid" @submit.prevent="createAdjustment"><div><label for="adjustment-product">Product</label><select id="adjustment-product" v-model.number="adjustmentProductId"><option :value="null" disabled>Select product</option><option v-for="product in products" :key="product.id" :value="product.id">{{ product.sku }} · {{ product.name }}</option></select></div><div><label for="adjustment-warehouse">Warehouse</label><select id="adjustment-warehouse" v-model.number="adjustmentWarehouseId"><option :value="null" disabled>Select warehouse</option><option v-for="warehouse in activeWarehouses" :key="warehouse.id" :value="warehouse.id">{{ warehouse.code }} · {{ warehouse.name }}</option></select></div><div><label for="adjustment-quantity">Signed quantity</label><input id="adjustment-quantity" v-model="adjustmentDelta" type="number" step="1" placeholder="+5 or -2" required /></div><div><label for="adjustment-date">Date</label><input id="adjustment-date" v-model="adjustmentDate" type="date" /></div><div v-if="canSeeFinance"><label for="adjustment-cost">Unit cost for extra stock (optional)</label><input id="adjustment-cost" v-model="adjustmentUnitCost" inputmode="decimal" placeholder="0.00" /></div><div><label for="adjustment-reason">Reason</label><input id="adjustment-reason" v-model="adjustmentReason" maxlength="255" required /></div><button class="edit-button" type="submit" :disabled="isSaving">Post adjustment</button></form>
      </section>
      <section class="inventory-section"><div class="inventory-section-heading"><div><p class="card-kicker">Sales dispatch</p><h2>Move shipped goods out of stock</h2></div></div>
        <div v-if="pendingShipments.length" class="inventory-document-list"><article v-for="shipment in pendingShipments" :key="shipment.shipment_id"><div><p class="card-kicker">{{ shipment.order_number }} · {{ shipment.shipped_at }}<span v-if="shipment.reference"> · {{ shipment.reference }}</span></p><h3>{{ shipment.client_name }}</h3><p class="muted">{{ shipment.items.map((line) => `${line.quantity} × ${line.description}`).join(" · ") }}</p></div><div class="inventory-dispatch-actions"><select v-model.number="dispatchWarehouseIds[shipment.shipment_id]" :aria-label="`Warehouse for ${shipment.order_number}`"><option :value="null" disabled>Select warehouse</option><option v-for="warehouse in activeWarehouses" :key="warehouse.id" :value="warehouse.id">{{ warehouse.code }}</option></select><button class="edit-button" type="button" :disabled="isSaving || !activeWarehouses.length" @click="dispatchShipment(shipment)">Dispatch</button></div></article></div><p v-else class="muted">No sales shipments are waiting for inventory dispatch.</p>
      </section>
      <section class="inventory-section"><div class="inventory-section-heading"><div><p class="card-kicker">Stock movement ledger</p><h2>Recent immutable movements</h2></div></div>
        <div v-if="movements.length" class="finance-table-wrap"><table class="finance-table"><thead><tr><th>Date</th><th>Product</th><th>Warehouse</th><th>Type</th><th>Delta</th><th>Reference</th><th>Memo</th></tr></thead><tbody><tr v-for="movement in movements" :key="movement.id"><td>{{ movement.moved_at }}</td><td>{{ movement.sku }} · {{ movement.product_name }}</td><td>{{ movement.warehouse_code }}</td><td>{{ movement.movement_type.replaceAll("_", " ") }}</td><td>{{ movement.quantity_delta > 0 ? "+" : "" }}{{ movement.quantity_delta }}</td><td>{{ movement.source_type }}<span v-if="movement.source_id"> #{{ movement.source_id }}</span></td><td>{{ movement.memo }}</td></tr></tbody></table></div><p v-else class="muted">No stock movements recorded.</p>
      </section>
    </template>

    <template v-else-if="tab === 'Purchasing'">
      <section class="inventory-section"><div class="inventory-section-heading"><div><p class="card-kicker">Purchase order</p><h2>Order products from a supplier</h2></div></div>
        <form class="inventory-purchase-form" @submit.prevent="createPurchaseOrder"><div class="inventory-form-grid"><div><label for="po-supplier">Supplier</label><select id="po-supplier" v-model.number="purchaseSupplierId"><option :value="null" disabled>Select supplier</option><option v-for="supplier in activeSuppliers" :key="supplier.id" :value="supplier.id">{{ supplier.code }} · {{ supplier.name }}</option></select></div><div><label for="po-currency">Currency</label><select id="po-currency" v-model="purchaseCurrency"><option v-for="currency in currencies" :key="currency">{{ currency }}</option></select></div><div><label for="po-date">Order date</label><input id="po-date" v-model="purchaseDate" type="date" required /></div></div>
          <div v-for="(line, index) in purchaseLines" :key="index" class="inventory-purchase-line"><select v-model.number="line.product_id" :aria-label="`Purchase product ${index + 1}`"><option :value="null" disabled>Select product</option><option v-for="product in products.filter((item) => item.active)" :key="product.id" :value="product.id">{{ product.sku }} · {{ product.name }}</option></select><input v-model.number="line.quantity" type="number" min="1" step="1" :aria-label="`Quantity for line ${index + 1}`" /><input v-model="line.unit_cost" inputmode="decimal" placeholder="Unit cost" :aria-label="`Unit cost for line ${index + 1}`" /><button class="close-button" type="button" :disabled="purchaseLines.length < 2" :aria-label="`Remove purchase line ${index + 1}`" @click="purchaseLines.splice(index, 1)">&#10005;</button></div>
          <div class="inventory-order-total"><button class="edit-button" type="button" @click="addPurchaseLine">Add product line</button><strong>Preview {{ formatMinor(purchaseTotal) }} {{ purchaseCurrency }}</strong></div><div class="task-form-actions"><button class="button article-submit" type="submit" :disabled="isSaving || !activeSuppliers.length || !products.length">{{ isSaving ? "Saving..." : "Create purchase order" }}</button></div>
        </form>
      </section>
      <section class="inventory-section"><div class="inventory-section-heading"><div><p class="card-kicker">Goods receipts</p><h2>Receive ordered quantities into a warehouse</h2></div></div>
        <div v-if="orders.length" class="inventory-document-list"><article v-for="order in orders" :key="order.id"><div><p class="card-kicker">{{ order.order_number }} · {{ order.status }} · {{ order.ordered_at }}</p><h3>{{ order.supplier_name }} · {{ order.total }} {{ order.currency }}</h3><p class="muted">{{ order.items.map((line) => `${line.received_quantity}/${line.quantity} ${line.description}`).join(" · ") }}</p>
            <form v-if="receiptOrderId === order.id" class="inventory-inline-receipt" @submit.prevent="receivePurchaseOrder(order)"><div class="inventory-form-grid"><div><label :for="`receipt-warehouse-${order.id}`">Warehouse</label><select :id="`receipt-warehouse-${order.id}`" v-model.number="receiptWarehouseId"><option :value="null" disabled>Select warehouse</option><option v-for="warehouse in activeWarehouses" :key="warehouse.id" :value="warehouse.id">{{ warehouse.code }} · {{ warehouse.name }}</option></select></div><div><label :for="`receipt-date-${order.id}`">Receipt date</label><input :id="`receipt-date-${order.id}`" v-model="receiptDate" type="date" required /></div><div><label :for="`receipt-ref-${order.id}`">Reference</label><input :id="`receipt-ref-${order.id}`" v-model="receiptReference" maxlength="80" /></div><div v-if="canSeeFinance"><label :for="`receipt-landed-${order.id}`">Landed cost ({{ order.currency }}, optional)</label><input :id="`receipt-landed-${order.id}`" v-model="receiptLandedCost" inputmode="decimal" placeholder="0.00" /></div></div><div v-for="line in order.items.filter((item) => item.received_quantity < item.quantity)" :key="line.product_id" class="inventory-receipt-line"><span>{{ line.description }} · {{ line.quantity - line.received_quantity }} remaining</span><input v-model="receiptQuantities[line.product_id]" type="number" min="0" :max="line.quantity - line.received_quantity" step="1" :aria-label="`Received quantity for ${line.description}`" /></div><div class="task-form-actions"><button class="edit-button" type="submit" :disabled="isSaving">{{ isSaving ? "Receiving..." : "Post goods receipt" }}</button><button class="edit-button" type="button" @click="receiptOrderId = null">Cancel</button></div></form>
          </div><button v-if="['open', 'partially_received'].includes(order.status) && receiptOrderId !== order.id" class="edit-button" type="button" :disabled="!activeWarehouses.length" @click="openReceipt(order)">Receive goods</button><div v-if="order.status === 'pending_approval'" class="task-form-actions"><span class="card-kicker">Awaiting approval<template v-if="order.created_by"> · created by {{ order.created_by }}</template></span><template v-if="canSeeFinance"><button class="edit-button" type="button" :disabled="isSaving" @click="decideOrder(order, 'approve')">Approve</button><button class="edit-button danger-button" type="button" :disabled="isSaving" @click="decideOrder(order, 'reject')">Reject</button></template></div></article></div><p v-else class="muted">No purchase orders recorded.</p>
      </section>
    </template>

    <InventoryValuationPanel v-else-if="tab === 'Valuation'" :products="products" :warehouses="warehouses" @changed="loadPage" />

    <template v-else>
      <section class="inventory-section"><div class="inventory-section-heading"><div><p class="card-kicker">Returns</p><h2>Record customer or supplier returns</h2></div></div>
        <form class="inventory-purchase-form" @submit.prevent="createReturn"><div class="inventory-form-grid"><div><label for="return-type">Return type</label><select id="return-type" v-model="returnType" @change="changeReturnType"><option value="customer">Customer return</option><option value="supplier">Supplier return</option></select></div><div><label for="return-source">Source document</label><select id="return-source" v-model.number="returnSourceId" @change="changeReturnSource"><option :value="null" disabled>Select source</option><option v-if="returnType === 'customer'" v-for="source in dispatchedShipments" :key="source.shipment_id" :value="source.shipment_id">{{ source.order_number }} · {{ source.client_name }} · {{ source.shipped_at }}</option><option v-else v-for="source in orders.filter((order) => order.items.some((line) => line.received_quantity > 0))" :key="source.id" :value="source.id">{{ source.order_number }} · {{ source.supplier_name }}</option></select></div><div><label for="return-warehouse">Warehouse</label><select id="return-warehouse" v-model.number="returnWarehouseId"><option :value="null" disabled>Select warehouse</option><option v-for="warehouse in activeWarehouses" :key="warehouse.id" :value="warehouse.id">{{ warehouse.code }} · {{ warehouse.name }}</option></select></div><div><label for="return-date">Return date</label><input id="return-date" v-model="returnDate" type="date" /></div><div><label for="return-reference">Reference</label><input id="return-reference" v-model="returnReference" maxlength="80" /></div></div>
          <div v-for="(line, index) in returnLines" :key="index" class="inventory-return-line"><select v-model.number="line.product_id" :aria-label="`Return item ${index + 1}`"><option :value="null" disabled>Select source item</option><option v-for="item in returnableLines" :key="item.product_id" :value="item.product_id">{{ item.description }} · max {{ item.quantity }}</option></select><input v-model.number="line.quantity" type="number" min="1" step="1" :aria-label="`Return quantity ${index + 1}`" /></div>
          <div class="task-form-actions"><button class="button article-submit" type="submit" :disabled="isSaving || returnSourceOptions.length === 0 || !activeWarehouses.length">{{ isSaving ? "Saving..." : "Record return" }}</button></div>
        </form>
      </section>
      <section class="inventory-section"><div class="inventory-section-heading"><div><p class="card-kicker">Return history</p><h2>Recent inventory returns</h2></div></div><div v-if="returns.length" class="inventory-document-list"><article v-for="item in returns" :key="item.id"><div><p class="card-kicker">{{ item.return_type }} · {{ item.returned_at }} · {{ item.warehouse_code }}</p><h3>{{ item.source_type }} #{{ item.source_id }}<span v-if="item.reference"> · {{ item.reference }}</span></h3><p class="muted">{{ item.items.map((line) => `${line.quantity} × ${line.description}`).join(" · ") }}</p></div></article></div><p v-else class="muted">No returns recorded.</p></section>
    </template>
  </div>
</template>