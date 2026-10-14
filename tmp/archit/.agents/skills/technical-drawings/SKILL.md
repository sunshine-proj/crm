---
name: technical-drawings
description: Generate architectural floor plans, elevations, sections, dimensions, schedules, drawing sheets, annotations, and printable construction documentation.
---

# Technical Drawings and Documentation

## Objective

Generate accurate, legible, and traceable technical documentation
from the architectural model.

## Sources of Truth

Building geometry and dimensions must originate from the authoritative
architectural model.

Drawing-specific presentation settings may be stored separately.

Never silently invent missing building dimensions or construction details.

## Drawing Types

Support the progressive development of:

- Floor plans.
- Furniture plans.
- Roof plans.
- Foundation plans.
- Exterior elevations.
- Building sections.
- Structural layouts.
- Door and window schedules.
- Room and area schedules.
- Material schedules.
- Drawing sheets.

## Projection and Visibility

Define explicit rules for:

- Visible and hidden edges.
- Cutting planes.
- Section geometry.
- Elevation projection.
- Level-specific visibility.
- Object classifications.
- Annotation visibility.

Do not generate technical sections by simply taking an arbitrary screenshot
of the 3D viewport.

## Dimensions

Dimension values must be calculated from model geometry.

Distinguish actual dimensions from rounded display values.

Support appropriate dimension styles, units, precision, and annotations.

Ensure that drawing scale does not alter the underlying dimension values.

Do not silently change dimension values to make drawings look better.

## Drawing Sheets

Support configurable:

- Page sizes.
- Margins.
- Drawing scales.
- Title blocks.
- Revision identifiers.
- Sheet numbers.
- Drawing numbers.
- Project metadata.
- Legends and notes.

Keep sheet layout separate from model geometry.

## Validation

Before export, check for:

- Missing required dimensions.
- Invalid references.
- Missing levels or drawing views.
- Inconsistent annotations.
- Unsupported object representations.
- Scale and unit inconsistencies.

Distinguish automated validation results from professional approval.

## Testing

Use fixtures with known geometry and expected drawing dimensions.

Test projection, dimension calculations, section visibility, annotation
placement, sheet layout, and export consistency.