<script setup lang="ts">
import { onMounted, ref } from "vue";
import { apiRequest, errorText } from "../lib/api";
import { getOrganizationDefaultCurrency } from "../lib/organizationSettings";
import { isAdminToken } from "../lib/session";

defineProps<{ products: { id: number; sku: string; name: string }[]; warehouses: { id: number; code: string; name: string }[] }>();
const emit = defineEmits<{ changed: [] }>();

type Row = {
  product_id: number; sku: string; product_name: string; warehouse_id: number; warehouse_code: string; currency: string;
  valued_quantity: number; value: string; average_unit_cost: string | null; on_hand_quantity: number; unvalued_quantity: number;
};
type Report = { enabled: boolean; method: string; rows: Row[]; totals: { currency: string; value: string }[]; basis: string };
type Policy = { threshold: string | null; enabled: boolean; basis: string };

const report = ref<Report | null>(null);
const policy = ref<Policy | null>(null);
const isLoading = ref(true);
const isSaving = ref(false);
const errorMessage = ref("");
const noticeMessage = ref("");
const isAdmin = isAdminToken(localStorage.getItem("crm_token"));
const thresholdInput = ref("");
const openingProductId = ref<number | null>(null);
const openingWarehouseId = ref<number | null>(null);
const openingUnitCost = ref("");
const openingCurrency = ref(getOrganizationDefaultCurrency());

async function load() {
  isLoading.value = true;
  errorMessage.value = "";
  try {
    [report.value, policy.value] = await Promise.all([apiRequest<Report>("/inventory/valuation"), apiRequest<Policy>("/inventory/purchase-approval-policy")]);
    thresholdInput.value = policy.value.threshold ?? "";
  } catch (error) {
    errorMessage.value = errorText(error, "Unable to load inventory valuation.");
  } finally {
    isLoading.value = false;
  }
}

function prefillOpening(row: Row) {
  openingProductId.value = row.product_id;
  openingWarehouseId.value = row.warehouse_id;
  openingCurrency.value = row.currency;
}

async function saveOpening() {
  if (openingProductId.value === null || openingWarehouseId.value === null || !openingUnitCost.value.trim()) {
    errorMessage.value = "Choose a product and warehouse and enter a unit cost.";
    return;
  }
  isSaving.value = true;
  errorMessage.value = "";
  noticeMessage.value = "";
  try {
    await apiRequest("/inventory/opening-valuations", {
      method: "POST",
      body: JSON.stringify({ product_id: openingProductId.value, warehouse_id: openingWarehouseId.value, unit_cost: openingUnitCost.value.trim(), currency: openingCurrency.value }),
    });
    noticeMessage.value = "Opening valuation recorded.";
    openingUnitCost.value = "";
    await load();
    emit("changed");
  } catch (error) {
    errorMessage.value = errorText(error, "Unable to record the opening valuation.");
  } finally {
    isSaving.value = false;
  }
}

async function savePolicy() {
  isSaving.value = true;
  errorMessage.value = "";
  noticeMessage.value = "";
  try {
    policy.value = await apiRequest<Policy>("/inventory/purchase-approval-policy", { method: "PUT", body: JSON.stringify({ threshold: thresholdInput.value.trim() || null }) });
    noticeMessage.value = policy.value.enabled ? "Approval threshold saved." : "Purchase-order approvals switched off.";
  } catch (error) {
    errorMessage.value = errorText(error, "Unable to save the approval policy.");
  } finally {
    isSaving.value = false;
  }
}

onMounted(load);
</script>

<template>
  <div>
    <p v-if="errorMessage" class="error" role="alert">{{ errorMessage }}</p>
    <p v-if="noticeMessage" class="sales-notice" role="status">{{ noticeMessage }}</p>
    <div v-if="isLoading" class="empty-page"><h2>Loading valuation...</h2></div>

    <template v-else-if="report">
      <section class="inventory-section">
        <div class="inventory-section-heading"><div><p class="card-kicker">Weighted-average cost</p><h2>Stock value by warehouse</h2></div></div>
        <p v-if="!report.enabled" class="error">Valuation is off. Map the inventory, cost of sales, goods received not invoiced and inventory adjustment accounts under Finance &rarr; Reporting &amp; mappings to value receipts, dispatches and returns.</p>
        <div v-if="report.rows.length" class="finance-table-wrap">
          <table class="finance-table">
            <thead><tr><th>Product</th><th>Warehouse</th><th>Valued qty</th><th>Average cost</th><th>Value</th><th>On hand</th><th>Unvalued</th></tr></thead>
            <tbody>
              <tr v-for="row in report.rows" :key="`${row.product_id}-${row.warehouse_id}`">
                <td>{{ row.sku }} · {{ row.product_name }}</td><td>{{ row.warehouse_code }}</td><td>{{ row.valued_quantity }}</td>
                <td>{{ row.average_unit_cost ?? "—" }} {{ row.currency }}</td><td>{{ row.value }} {{ row.currency }}</td><td>{{ row.on_hand_quantity }}</td>
                <td>{{ row.unvalued_quantity }}<button v-if="row.unvalued_quantity > 0" class="edit-button" type="button" @click="prefillOpening(row)">Value</button></td>
              </tr>
              <tr v-for="total in report.totals" :key="total.currency" class="finance-table-total"><th colspan="4">Total {{ total.currency }}</th><th>{{ total.value }} {{ total.currency }}</th><th colspan="2"></th></tr>
            </tbody>
          </table>
        </div>
        <p v-else class="muted">No valued stock yet.</p>
        <p class="finance-report-basis">{{ report.basis }}</p>
      </section>

      <section v-if="report.enabled" class="inventory-section">
        <div class="inventory-section-heading"><div><p class="card-kicker">Legacy stock</p><h2>Opening valuation</h2></div></div>
        <p class="muted">Gives a cost to quantity received before valuation was enabled. The offsetting entry goes to the inventory adjustment account and should be reviewed by your accountant.</p>
        <form class="inventory-form-grid" @submit.prevent="saveOpening">
          <div><label for="opening-product">Product</label><select id="opening-product" v-model.number="openingProductId"><option :value="null" disabled>Select product</option><option v-for="product in products" :key="product.id" :value="product.id">{{ product.sku }} · {{ product.name }}</option></select></div>
          <div><label for="opening-warehouse">Warehouse</label><select id="opening-warehouse" v-model.number="openingWarehouseId"><option :value="null" disabled>Select warehouse</option><option v-for="warehouse in warehouses" :key="warehouse.id" :value="warehouse.id">{{ warehouse.code }}</option></select></div>
          <div><label for="opening-cost">Unit cost</label><input id="opening-cost" v-model="openingUnitCost" inputmode="decimal" placeholder="0.00" required /></div>
          <div><label for="opening-currency">Currency</label><select id="opening-currency" v-model="openingCurrency"><option>EUR</option><option>GBP</option><option>USD</option></select></div>
          <button class="button article-submit" type="submit" :disabled="isSaving">Record opening valuation</button>
        </form>
      </section>

      <section v-if="policy" class="inventory-section">
        <div class="inventory-section-heading"><div><p class="card-kicker">Purchasing controls</p><h2>Purchase-order approval</h2></div></div>
        <p>{{ policy.enabled ? `Orders of ${policy.threshold} or more (and any order in another currency) need approval.` : "Approvals are off: every order can receive goods immediately." }}</p>
        <form v-if="isAdmin" class="inventory-form-grid" @submit.prevent="savePolicy">
          <div><label for="approval-threshold">Approval threshold (blank = off)</label><input id="approval-threshold" v-model="thresholdInput" inputmode="decimal" placeholder="0.00" /></div>
          <button class="button article-submit" type="submit" :disabled="isSaving">Save policy</button>
        </form>
        <p class="finance-report-basis">{{ policy.basis }}</p>
      </section>
    </template>
  </div>
</template>
