<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { getOrganizationDefaultCurrency } from "../lib/organizationSettings";

type Client = { id: number; title: string };
type Product = {
  id: number;
  sku: string;
  name: string;
  description: string | null;
  currency: string;
  unit_price: string;
  active: boolean;
};
type SalesLine = {
  product_id: number;
  sku: string;
  description: string;
  quantity: number;
  unit_price: string;
  line_total: string;
};
type Shipment = {
  id: number;
  shipped_at: string;
  reference: string | null;
  items: { product_id: number; description: string; quantity: number }[];
};
type SalesDocument = {
  id: number;
  type: "quote" | "sales_order";
  document_number: string;
  client_id: number;
  client_name: string;
  source_quote_id: number | null;
  currency: string;
  items: SalesLine[];
  total: string;
  status: string;
  valid_until: string | null;
  shipments?: Shipment[];
  invoice_id?: number | null;
};
type Invoice = {
  id: number;
  invoice_number: string;
  client_name: string;
  currency: string;
  total: string;
  status: string;
};

const currencies = ["EUR", "GBP", "USD"];
const sections = ["Products", "Quotes", "Sales orders"] as const;
type Section = (typeof sections)[number];
const section = ref<Section>("Products");
const clients = ref<Client[]>([]);
const products = ref<Product[]>([]);
const quotes = ref<SalesDocument[]>([]);
const orders = ref<SalesDocument[]>([]);
const invoices = ref<Invoice[]>([]);
const isLoading = ref(true);
const errorMessage = ref("");
const noticeMessage = ref("");
const isSaving = ref(false);
const productFormVisible = ref(false);
const editingProductId = ref<number | null>(null);
const productSku = ref("");
const productName = ref("");
const productDescription = ref("");
const productCurrency = ref(getOrganizationDefaultCurrency());
const productPrice = ref("");
const productActive = ref(true);
const quoteFormVisible = ref(false);
const quoteClientId = ref<number | null>(null);
const quoteCurrency = ref(getOrganizationDefaultCurrency());
const quoteValidUntil = ref("");
const quoteProductId = ref<number | null>(null);
const quoteQuantity = ref(1);
const quoteLines = ref<{ product_id: number; quantity: number }[]>([]);
const shipmentOrderId = ref<number | null>(null);
const shipmentDate = ref("");
const shipmentReference = ref("");
const shipmentQuantities = ref<Record<number, string>>({});
const invoiceOrderId = ref<number | null>(null);
const invoiceDate = ref("");
const invoiceDueDate = ref("");

const quoteProducts = computed(() => products.value.filter((product) => product.active && product.currency === quoteCurrency.value));
const quotePreviewTotal = computed(() => {
  let total = 0n;
  for (const line of quoteLines.value) {
    const product = products.value.find((entry) => entry.id === line.product_id);
    if (product) total += parseMinor(product.unit_price) * BigInt(line.quantity);
  }
  return formatMinor(total);
});

function parseMinor(value: string): bigint {
  const match = /^(0|[1-9]\d*)(?:\.(\d{1,2}))?$/.exec(value);
  if (!match) return 0n;
  return BigInt(match[1]) * 100n + BigInt((match[2] ?? "").padEnd(2, "0"));
}

function formatMinor(value: bigint): string {
  return `${value / 100n}.${String(value % 100n).padStart(2, "0")}`;
}

async function request<T>(path: string, options: RequestInit = {}): Promise<T> {
  const token = localStorage.getItem("crm_token");
  const response = await fetch(`/api${path}`, {
    ...options,
    headers: {
      "Content-Type": "application/json",
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...options.headers,
    },
  });
  if (!response.ok) {
    const payload = (await response.json().catch(() => null)) as { error?: string } | null;
    throw new Error(payload?.error ?? "Unable to complete the sales request.");
  }
  return response.status === 204 ? (undefined as T) : ((await response.json()) as T);
}

async function loadPage() {
  isLoading.value = true;
  errorMessage.value = "";
  try {
    const [loadedClients, loadedProducts, loadedQuotes, loadedOrders, loadedInvoices] = await Promise.all([
      request<Client[]>("/clients"),
      request<Product[]>("/products"),
      request<SalesDocument[]>("/quotes"),
      request<SalesDocument[]>("/sales-orders"),
      request<Invoice[]>("/invoices"),
    ]);
    clients.value = loadedClients;
    products.value = loadedProducts;
    quotes.value = loadedQuotes;
    orders.value = loadedOrders;
    invoices.value = loadedInvoices;
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : "Unable to load sales records.";
  } finally {
    isLoading.value = false;
  }
}

onMounted(loadPage);

function resetProductForm() {
  editingProductId.value = null;
  productSku.value = "";
  productName.value = "";
  productDescription.value = "";
  productCurrency.value = getOrganizationDefaultCurrency();
  productPrice.value = "";
  productActive.value = true;
  productFormVisible.value = true;
}

function editProduct(product: Product) {
  editingProductId.value = product.id;
  productSku.value = product.sku;
  productName.value = product.name;
  productDescription.value = product.description ?? "";
  productCurrency.value = product.currency;
  productPrice.value = product.unit_price;
  productActive.value = product.active;
  productFormVisible.value = true;
}

async function saveProduct() {
  errorMessage.value = "";
  isSaving.value = true;
  try {
    const payload = {
      sku: productSku.value.trim(),
      name: productName.value.trim(),
      description: productDescription.value.trim() || null,
      currency: productCurrency.value,
      unit_price: productPrice.value,
      active: productActive.value,
    };
    const id = editingProductId.value;
    const product = await request<Product>(id === null ? "/products" : `/products/${id}`, {
      method: id === null ? "POST" : "PUT",
      body: JSON.stringify(payload),
    });
    const index = products.value.findIndex((item) => item.id === product.id);
    if (index < 0) products.value.push(product);
    else products.value[index] = product;
    productFormVisible.value = false;
    noticeMessage.value = "Product pricing saved.";
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : "Unable to save product pricing.";
  } finally {
    isSaving.value = false;
  }
}

function startQuote() {
  quoteClientId.value = clients.value[0]?.id ?? null;
  quoteCurrency.value = getOrganizationDefaultCurrency();
  quoteValidUntil.value = "";
  quoteProductId.value = quoteProducts.value[0]?.id ?? null;
  quoteQuantity.value = 1;
  quoteLines.value = [];
  quoteFormVisible.value = true;
}

function addQuoteLine() {
  if (quoteProductId.value === null || !Number.isInteger(quoteQuantity.value) || quoteQuantity.value < 1) return;
  const current = quoteLines.value.find((line) => line.product_id === quoteProductId.value);
  if (current) current.quantity += quoteQuantity.value;
  else quoteLines.value.push({ product_id: quoteProductId.value, quantity: quoteQuantity.value });
}

function changeQuoteCurrency() {
  quoteLines.value = quoteLines.value.filter((line) => quoteProducts.value.some((product) => product.id === line.product_id));
  quoteProductId.value = quoteProducts.value[0]?.id ?? null;
}

async function saveQuote() {
  if (quoteClientId.value === null || quoteLines.value.length === 0) {
    errorMessage.value = "Select a client and add at least one priced product.";
    return;
  }
  isSaving.value = true;
  errorMessage.value = "";
  try {
    const quote = await request<SalesDocument>("/quotes", {
      method: "POST",
      body: JSON.stringify({
        client_id: quoteClientId.value,
        currency: quoteCurrency.value,
        valid_until: quoteValidUntil.value || null,
        items: quoteLines.value,
      }),
    });
    quotes.value.unshift(quote);
    quoteFormVisible.value = false;
    noticeMessage.value = `${quote.document_number} created as a draft.`;
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : "Unable to create quote.";
  } finally {
    isSaving.value = false;
  }
}

async function changeQuoteStatus(quote: SalesDocument, status: string) {
  errorMessage.value = "";
  try {
    const updated = await request<SalesDocument>(`/quotes/${quote.id}/status`, {
      method: "PATCH",
      body: JSON.stringify({ status }),
    });
    quotes.value = quotes.value.map((item) => item.id === updated.id ? updated : item);
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : "Unable to update quote status.";
  }
}

async function convertQuote(quote: SalesDocument) {
  errorMessage.value = "";
  try {
    const order = await request<SalesDocument>(`/quotes/${quote.id}/convert`, { method: "POST" });
    quotes.value = quotes.value.map((item) => item.id === quote.id ? { ...item, status: "converted" } : item);
    if (!orders.value.some((item) => item.id === order.id)) orders.value.unshift({ ...order, shipments: [], invoice_id: null });
    noticeMessage.value = `${order.document_number} created from ${quote.document_number}.`;
    section.value = "Sales orders";
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : "Unable to convert quote.";
  }
}

function openShipment(order: SalesDocument) {
  shipmentOrderId.value = order.id;
  shipmentDate.value = new Date().toISOString().slice(0, 10);
  shipmentReference.value = "";
  const shipped = new Map<number, number>();
  for (const shipment of order.shipments ?? []) {
    for (const line of shipment.items) shipped.set(line.product_id, (shipped.get(line.product_id) ?? 0) + line.quantity);
  }
  shipmentQuantities.value = Object.fromEntries(order.items.map((line) => [line.product_id, String(Math.max(0, line.quantity - (shipped.get(line.product_id) ?? 0)))]));
}

async function saveShipment(order: SalesDocument) {
  const items = order.items.map((line) => ({ product_id: line.product_id, quantity: Number(shipmentQuantities.value[line.product_id] || 0) })).filter((line) => line.quantity > 0);
  if (items.length === 0) {
    errorMessage.value = "Enter a quantity for at least one unshipped product.";
    return;
  }
  isSaving.value = true;
  errorMessage.value = "";
  try {
    await request<Shipment>(`/sales-orders/${order.id}/shipments`, {
      method: "POST",
      body: JSON.stringify({ shipped_at: shipmentDate.value, reference: shipmentReference.value || null, items }),
    });
    shipmentOrderId.value = null;
    await loadPage();
    noticeMessage.value = `Shipment recorded for ${order.document_number}.`;
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : "Unable to record shipment.";
  } finally {
    isSaving.value = false;
  }
}

function openOrderInvoice(order: SalesDocument) {
  invoiceOrderId.value = order.id;
  invoiceDate.value = new Date().toISOString().slice(0, 10);
  invoiceDueDate.value = "";
}

async function createOrderInvoice(order: SalesDocument) {
  isSaving.value = true;
  errorMessage.value = "";
  try {
    const invoice = await request<Invoice>(`/sales-orders/${order.id}/invoice`, {
      method: "POST",
      body: JSON.stringify({ invoice_date: invoiceDate.value, due_date: invoiceDueDate.value || null }),
    });
    invoiceOrderId.value = null;
    await loadPage();
    noticeMessage.value = `${invoice.invoice_number} created as a draft. Issue it from Invoices before recording payments.`;
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : "Unable to create invoice.";
  } finally {
    isSaving.value = false;
  }
}
</script>

<template>
  <div class="page-content sales-page">
    <div class="page-heading">
      <div>
        <p class="eyebrow">Sales operations</p>
        <h1>From priced product to paid invoice.</h1>
      </div>
      <button v-if="section === 'Products' && !productFormVisible" class="create-button" type="button" @click="resetProductForm">+ <span>Add product</span></button>
      <button v-if="section === 'Quotes' && !quoteFormVisible" class="create-button" type="button" :disabled="products.every((product) => !product.active)" @click="startQuote">+ <span>New quote</span></button>
    </div>

    <nav class="sales-tabs" aria-label="Sales records">
      <button v-for="tab in sections" :key="tab" type="button" :class="{ active: section === tab }" :aria-pressed="section === tab" @click="section = tab; errorMessage = ''; noticeMessage = ''">{{ tab }}</button>
    </nav>

    <p v-if="errorMessage" class="error" role="alert">{{ errorMessage }}</p>
    <p v-if="noticeMessage" class="sales-notice" role="status">{{ noticeMessage }}</p>
    <div v-if="isLoading" class="empty-page"><h2>Loading sales records...</h2></div>

    <template v-else-if="section === 'Products'">
      <form v-if="productFormVisible" class="article-form sales-form" @submit.prevent="saveProduct">
        <div class="article-form-heading">
          <div><p class="card-kicker">{{ editingProductId === null ? "Product and price" : "Update product price" }}</p><h2>Catalog item</h2></div>
          <button class="close-button" type="button" aria-label="Close product form" @click="productFormVisible = false">&#10005;</button>
        </div>
        <div class="sales-form-grid">
          <div><label for="product-sku">SKU</label><input id="product-sku" v-model="productSku" maxlength="64" required /></div>
          <div><label for="product-name">Name</label><input id="product-name" v-model="productName" maxlength="255" required /></div>
          <div><label for="product-currency">Currency</label><select id="product-currency" v-model="productCurrency"><option v-for="currency in currencies" :key="currency">{{ currency }}</option></select></div>
          <div><label for="product-price">Unit price</label><input id="product-price" v-model="productPrice" inputmode="decimal" placeholder="0.00" required /></div>
        </div>
        <label for="product-description">Description</label><textarea id="product-description" v-model="productDescription" rows="2"></textarea>
        <label class="sales-active-toggle"><input v-model="productActive" type="checkbox" /> Active for new quotes</label>
        <div class="task-form-actions"><button class="button article-submit" type="submit" :disabled="isSaving">{{ isSaving ? "Saving..." : "Save product" }}</button><button class="edit-button" type="button" @click="productFormVisible = false">Cancel</button></div>
      </form>

      <div v-if="products.length" class="article-list">
        <article v-for="product in products" :key="product.id" class="article-item sales-product-row">
          <div><p class="card-kicker">{{ product.sku }} · {{ product.active ? "Active" : "Inactive" }}</p><h2>{{ product.name }}</h2><p v-if="product.description" class="muted">{{ product.description }}</p></div>
          <div class="sales-product-price"><strong>{{ product.unit_price }} {{ product.currency }}</strong><button class="edit-button" type="button" @click="editProduct(product)">Edit price</button></div>
        </article>
      </div>
      <div v-else-if="!productFormVisible" class="empty-page"><h2>Product catalog is empty.</h2><p class="muted">Add priced products to prepare consistent quotes.</p></div>
    </template>

    <template v-else-if="section === 'Quotes'">
      <form v-if="quoteFormVisible" class="article-form sales-form" @submit.prevent="saveQuote">
        <div class="article-form-heading"><div><p class="card-kicker">New quote</p><h2>Price an offer for a client.</h2></div><button class="close-button" type="button" aria-label="Close quote form" @click="quoteFormVisible = false">&#10005;</button></div>
        <div class="sales-form-grid">
          <div><label for="quote-client">Client</label><select id="quote-client" v-model="quoteClientId" required><option v-for="client in clients" :key="client.id" :value="client.id">{{ client.title }}</option></select></div>
          <div><label for="quote-currency">Currency</label><select id="quote-currency" v-model="quoteCurrency" @change="changeQuoteCurrency"><option v-for="currency in currencies" :key="currency">{{ currency }}</option></select></div>
          <div><label for="quote-expiry">Valid until</label><input id="quote-expiry" v-model="quoteValidUntil" type="date" /></div>
        </div>
        <div class="sales-add-line"><div><label for="quote-product">Priced product</label><select id="quote-product" v-model="quoteProductId"><option v-for="product in quoteProducts" :key="product.id" :value="product.id">{{ product.sku }} · {{ product.name }} · {{ product.unit_price }} {{ product.currency }}</option></select></div><div><label for="quote-quantity">Quantity</label><input id="quote-quantity" v-model.number="quoteQuantity" type="number" min="1" step="1" /></div><button class="edit-button" type="button" :disabled="quoteProductId === null" @click="addQuoteLine">Add line</button></div>
        <div v-if="quoteLines.length" class="sales-lines">
          <div v-for="line in quoteLines" :key="line.product_id" class="sales-line-row"><span>{{ products.find((product) => product.id === line.product_id)?.name }}</span><span>{{ line.quantity }} × {{ products.find((product) => product.id === line.product_id)?.unit_price }} {{ quoteCurrency }}</span><button class="close-button" type="button" aria-label="Remove quote line" @click="quoteLines = quoteLines.filter((item) => item.product_id !== line.product_id)">&#10005;</button></div>
          <div class="sales-quote-total"><span>Quote total</span><strong>{{ quotePreviewTotal }} {{ quoteCurrency }}</strong></div>
        </div>
        <div class="task-form-actions"><button class="button article-submit" type="submit" :disabled="isSaving || clients.length === 0 || quoteLines.length === 0">{{ isSaving ? "Saving..." : "Save draft quote" }}</button><button class="edit-button" type="button" @click="quoteFormVisible = false">Cancel</button></div>
      </form>
      <div v-if="quotes.length" class="article-list">
        <article v-for="quote in quotes" :key="quote.id" class="article-item sales-document-row">
          <div><p class="card-kicker">{{ quote.document_number }} · {{ quote.status }}<span v-if="quote.valid_until"> · Valid until {{ quote.valid_until }}</span></p><h2>{{ quote.client_name }}</h2><p class="muted">{{ quote.items.map((line) => `${line.quantity} × ${line.description}`).join(" · ") }}</p></div>
          <div class="sales-document-actions"><strong>{{ quote.total }} {{ quote.currency }}</strong><button v-if="quote.status === 'draft'" class="edit-button" type="button" @click="changeQuoteStatus(quote, 'sent')">Mark sent</button><template v-if="quote.status === 'sent'"><button class="edit-button" type="button" @click="changeQuoteStatus(quote, 'accepted')">Accept</button><button class="edit-button" type="button" @click="changeQuoteStatus(quote, 'rejected')">Reject</button></template><button v-if="quote.status === 'accepted'" class="edit-button" type="button" @click="convertQuote(quote)">Create order</button></div>
        </article>
      </div>
      <div v-else-if="!quoteFormVisible" class="empty-page"><h2>No quotes yet.</h2><p class="muted">Create an offer from the current product prices.</p></div>
    </template>

    <template v-else>
      <div v-if="orders.length" class="article-list">
        <article v-for="order in orders" :key="order.id" class="article-item sales-order-card">
          <div class="sales-document-row">
            <div><p class="card-kicker">{{ order.document_number }} · {{ order.status }}</p><h2>{{ order.client_name }}</h2><p class="muted">{{ order.items.map((line) => `${line.quantity} × ${line.description}`).join(" · ") }}</p><p class="muted">From {{ order.source_quote_id ? quotes.find((quote) => quote.id === order.source_quote_id)?.document_number ?? `quote ${order.source_quote_id}` : "manual order" }}</p></div>
            <div class="sales-document-actions"><strong>{{ order.total }} {{ order.currency }}</strong><button v-if="['open', 'partially_shipped'].includes(order.status)" class="edit-button" type="button" @click="openShipment(order)">Record shipment</button><button v-if="order.status === 'shipped' && !order.invoice_id" class="edit-button" type="button" @click="openOrderInvoice(order)">Create invoice</button><span v-if="order.invoice_id" class="card-kicker">Invoice #{{ order.invoice_id }}</span></div>
          </div>
          <form v-if="shipmentOrderId === order.id" class="sales-inline-form" @submit.prevent="saveShipment(order)">
            <h3>Record shipment</h3><div class="sales-form-grid"><div><label :for="`shipment-date-${order.id}`">Ship date</label><input :id="`shipment-date-${order.id}`" v-model="shipmentDate" type="date" required /></div><div><label :for="`shipment-reference-${order.id}`">Reference</label><input :id="`shipment-reference-${order.id}`" v-model="shipmentReference" maxlength="64" /></div></div>
            <div v-for="line in order.items" :key="line.product_id" class="sales-line-row"><label :for="`shipment-qty-${order.id}-${line.product_id}`">{{ line.description }} · remaining {{ shipmentQuantities[line.product_id] }}</label><input :id="`shipment-qty-${order.id}-${line.product_id}`" v-model="shipmentQuantities[line.product_id]" type="number" min="0" :max="shipmentQuantities[line.product_id]" step="1" /></div>
            <div class="task-form-actions"><button class="edit-button" type="submit" :disabled="isSaving">{{ isSaving ? "Saving..." : "Save shipment" }}</button><button class="edit-button" type="button" @click="shipmentOrderId = null">Cancel</button></div>
          </form>
          <form v-if="invoiceOrderId === order.id" class="sales-inline-form" @submit.prevent="createOrderInvoice(order)">
            <h3>Invoice this order</h3><div class="sales-form-grid"><div><label :for="`order-invoice-date-${order.id}`">Invoice date</label><input :id="`order-invoice-date-${order.id}`" v-model="invoiceDate" type="date" required /></div><div><label :for="`order-invoice-due-${order.id}`">Due date</label><input :id="`order-invoice-due-${order.id}`" v-model="invoiceDueDate" type="date" /></div></div>
            <div class="task-form-actions"><button class="edit-button" type="submit" :disabled="isSaving">{{ isSaving ? "Creating..." : "Create draft invoice" }}</button><button class="edit-button" type="button" @click="invoiceOrderId = null">Cancel</button></div>
          </form>
          <div v-if="order.shipments?.length" class="sales-shipment-history"><h3>Shipments</h3><p v-for="shipment in order.shipments" :key="shipment.id" class="muted">{{ shipment.shipped_at }}<span v-if="shipment.reference"> · {{ shipment.reference }}</span> · {{ shipment.items.map((line) => `${line.quantity} × ${line.description}`).join(", ") }}</p></div>
        </article>
      </div>
      <div v-else class="empty-page"><h2>No sales orders yet.</h2><p class="muted">Accept a quote to create the first order.</p></div>
      <div v-if="invoices.length" class="sales-invoice-reference"><p class="card-kicker">Invoice records</p><p v-for="invoice in invoices" :key="invoice.id">{{ invoice.invoice_number }} · {{ invoice.client_name }} · {{ invoice.total }} {{ invoice.currency }} · {{ invoice.status }}</p></div>
    </template>
  </div>
</template>