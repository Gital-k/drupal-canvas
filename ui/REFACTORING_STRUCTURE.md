# File Structure Visualization

## Before Refactoring
```
withFormRegistration.tsx (1,141 lines)
├── Imports (56 lines)
├── Helper functions (62 lines)
│   ├── dispatchFieldValue
│   ├── dispatchFieldError
│   └── extractValue
├── ComponentFormField (464 lines)
│   ├── Undo/redo sync
│   ├── formStateToStore
│   ├── parseNewValue
│   ├── validateNewValue
│   ├── commitFormState
│   └── Controller render
├── PageDataFormField (288 lines)
│   ├── Undo/redo sync
│   ├── formStateToStore
│   ├── parseNewValue
│   ├── validateNewValue
│   └── Controller render
└── withFormRegistration HOC (158 lines)
    ├── Context setup
    ├── Form build ID handling
    ├── AJAX field registration
    ├── Uncontrolled fallback
    └── Component routing
```

## After Refactoring
```
withFormRegistration.tsx (191 lines)
├── Imports from extracted modules
└── HOC (orchestration only)
    ├── Context setup
    ├── useFormBuildIdSync (hook)
    ├── useAjaxFieldRegistration (hook)
    ├── Uncontrolled fallback
    └── Component routing
        ├── → ComponentFormField
        └── → PageDataFormField

fields/
├── ComponentFormField.tsx (449 lines)
│   ├── useComponentFormInputInfo
│   ├── useUndoRedoSync (hook)
│   ├── formStateToStore
│   ├── parseNewValue
│   ├── validateNewValue
│   ├── commitFormState
│   └── Controller render
└── PageDataFormField.tsx (299 lines)
    ├── usePageDataFormInputInfo
    ├── useUndoRedoSync (hook)
    ├── formStateToStore
    ├── parseNewValue
    ├── validateNewValue
    └── Controller render

hooks/
├── useUndoRedoSync.ts (62 lines)
│   └── Syncs RHF with Redux on undo/redo
├── useFormBuildIdSync.ts (67 lines)
│   └── Handles AJAX form_build_id updates
└── useAjaxFieldRegistration.ts (53 lines)
    └── Registers AJAX-added fields

utils/
├── formFieldHelpers.ts (69 lines)
│   ├── dispatchFieldValue
│   ├── dispatchFieldError
│   └── extractValue
└── validationHelpers.ts (18 lines)
    └── shouldUpdateFormState

types/
└── formFieldTypes.ts (23 lines)
    ├── ValidationResult
    └── FormFieldProps
```

## Import Flow
```
withFormRegistration.tsx
    ↓ imports
    ├── fields/ComponentFormField
    │   ↓ imports
    │   ├── hooks/useUndoRedoSync
    │   ├── hooks/useComponentFormInputInfo
    │   ├── utils/formFieldHelpers
    │   ├── utils/validationHelpers
    │   └── types/formFieldTypes
    │
    ├── fields/PageDataFormField
    │   ↓ imports
    │   ├── hooks/useUndoRedoSync
    │   ├── hooks/usePageDataFormInputInfo
    │   ├── utils/formFieldHelpers
    │   └── types/formFieldTypes
    │
    ├── hooks/useFormBuildIdSync
    │   ↓ imports
    │   └── utils/formFieldHelpers
    │
    ├── hooks/useAjaxFieldRegistration
    │
    └── utils/formFieldHelpers
```

## File Size Comparison

| File | Before | After | Change |
|------|--------|-------|--------|
| withFormRegistration.tsx | 1,141 lines | 191 lines | -83% |
| **Total lines of code** | 1,141 | 1,200* | +5% |

*The slight increase is due to:
- Added module boundaries (imports/exports)
- Better documentation comments
- Improved formatting and readability

The 5% code increase is a worthwhile trade-off for the significant improvement in:
- Maintainability
- Testability
- Modularity
- Developer experience

