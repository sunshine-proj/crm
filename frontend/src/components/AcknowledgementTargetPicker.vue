<script setup lang="ts">
import { computed, ref } from "vue";
import { treeRows } from "../lib/org";
import { searchPeople, type AckAudience, type AckSelection } from "../lib/acknowledgements";

const props = defineProps<{ audience: AckAudience; idPrefix: string }>();
const selection = defineModel<AckSelection>({ required: true });

const personQuery = ref("");
const unitRows = computed(() => treeRows(props.audience.units));
const people = computed(() => searchPeople(props.audience.people, personQuery.value).slice(0, 100));

function toggleUnit(unitId: number, checked: boolean) {
  const units = { ...selection.value.units };
  if (checked) units[unitId] = units[unitId] ?? true;
  else delete units[unitId];
  selection.value = { ...selection.value, units };
}

function setSubunits(unitId: number, include: boolean) {
  selection.value = { ...selection.value, units: { ...selection.value.units, [unitId]: include } };
}
</script>

<template>
  <div class="ack-picker">
    <p class="muted org-hint">
      {{ audience.organization_wide ? "As a registry clerk you can address the whole organization." : "You can address the units you head and everything below them." }}
      People who hold a post when the request is sent are recorded with their unit and position.
    </p>
    <fieldset class="dms-fieldset">
      <legend>Units and sections</legend>
      <p v-if="unitRows.length === 0" class="muted">No units.</p>
      <div v-for="row in unitRows" :key="row.unit.id" class="ack-picker-unit" :style="{ paddingLeft: `${row.level * 18}px` }">
        <label class="pm-all-projects-toggle">
          <input type="checkbox" :checked="row.unit.id in selection.units" @change="toggleUnit(row.unit.id, ($event.target as HTMLInputElement).checked)" />
          {{ row.unit.name }} <span class="muted">· {{ row.unit.member_count }} {{ row.unit.member_count === 1 ? "person" : "people" }}</span>
        </label>
        <label v-if="row.hasChildren && row.unit.id in selection.units" class="pm-all-projects-toggle ack-subunits">
          <input type="checkbox" :checked="selection.units[row.unit.id]" @change="setSubunits(row.unit.id, ($event.target as HTMLInputElement).checked)" /> include sub-units
        </label>
      </div>
    </fieldset>
    <fieldset v-if="audience.positions.length" class="dms-fieldset">
      <legend>Positions</legend>
      <div class="ack-picker-scroll">
        <label v-for="position in audience.positions" :key="position.id" class="pm-all-projects-toggle">
          <input v-model="selection.positions" type="checkbox" :value="position.id" />
          {{ position.title }} <span class="muted">· {{ position.unit_name }} · {{ position.holder_count }} holder{{ position.holder_count === 1 ? "" : "s" }}</span>
        </label>
      </div>
    </fieldset>
    <fieldset class="dms-fieldset">
      <legend>Individual employees</legend>
      <label :for="`${idPrefix}-person-search`" class="visually-hidden">Search employees</label>
      <input :id="`${idPrefix}-person-search`" v-model="personQuery" type="search" placeholder="Search by e-mail, position or unit" />
      <div class="ack-picker-scroll">
        <label v-for="person in people" :key="person.id" class="pm-all-projects-toggle">
          <input v-model="selection.people" type="checkbox" :value="person.id" />
          {{ person.email }} <span class="muted">· {{ person.position_title ?? "no position" }}<template v-if="person.unit_name">, {{ person.unit_name }}</template></span>
        </label>
        <p v-if="people.length === 0" class="muted">Nobody matches.</p>
      </div>
    </fieldset>
  </div>
</template>
