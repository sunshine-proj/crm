---
name: cad-bim-interoperability
description: Implement import and export adapters for DXF, DWG integrations, IFC, PDF, SVG, and glTF/GLB, including unit conversion, metadata preservation, and interoperability validation.
---

# CAD and BIM Interoperability

## Objective

Exchange architectural data with external design and documentation
applications while preserving supported information.

## Architecture

Use dedicated adapters for each format.

Keep external-format details outside the core architectural model.

Define explicit mappings between internal entities and external entities.

Document what information can be represented by each format.

## Import

Before importing:

1. Validate the file and its size.
2. Identify the format and supported version.
3. Determine source units and coordinate conventions.
4. Parse the supported entities.
5. Map entities into internal architectural types.
6. Validate relationships and geometry.
7. Report unsupported or ambiguous information.
8. Preview the result where practical.
9. Commit the import only after validation.

Do not overwrite an existing project without explicit authorization.

Treat imported files as untrusted input.

## Export

Before exporting:

1. Validate the project model.
2. Check required information.
3. Resolve unit conversions.
4. Map architectural objects to supported target entities.
5. Preserve supported identifiers and metadata.
6. Generate the output file.
7. Validate the output.
8. Report unsupported features and information loss.

## Format Responsibilities

### DXF

Support selected 2D entities, layers, dimensions, and annotations.

Add 3D entities only when their mapping and target compatibility are defined.

### DWG

Use a validated integration or conversion workflow.

Do not assume that renaming a DXF file creates a valid DWG file.

### IFC

Preserve supported building entities, relationships, units, and properties.

Validate generated IFC files using appropriate readers and schema checks.

### PDF and SVG

Use vector output where possible for technical drawings.

Preserve page size, scale, line weights, and text readability.

### GLB and glTF

Export supported 3D geometry, materials, textures, and scene hierarchy.

Do not assume that a visualization export preserves all BIM properties.

## Testing

Use representative test files.

Validate exported files in independent readers whenever practical.

Test units, geometry, layers, dimensions, metadata, and round-trip behavior.

Document unsupported features rather than silently discarding them.
