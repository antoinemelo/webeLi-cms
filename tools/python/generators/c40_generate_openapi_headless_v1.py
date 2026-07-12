#!/usr/bin/env python3
"""Génère un OpenAPI 3.1 léger pour l'API headless publique v1.

Source unique: les contrats JSON de docs/reference/contracts/headless-v1/.
Aucune dépendance externe n'est requise.
"""
from __future__ import annotations

import json
import re
import sys
from copy import deepcopy
from pathlib import Path
from typing import Any

ROOT = next(parent for parent in Path(__file__).resolve().parents if (parent / "tools" / "cms.py").is_file())
CONTRACT_DIR = ROOT / "docs/reference/contracts/headless-v1"
OUTPUT_JSON_PATH = ROOT / "docs/public-api/openapi.v1.json"
OUTPUT_YAML_PATH = ROOT / "docs/public-api/openapi.v1.yaml"
REFERENCE_JSON_PATH = ROOT / "docs/reference/contracts/public-api/openapi.v1.json"
REFERENCE_YAML_PATH = ROOT / "docs/reference/contracts/public-api/openapi.v1.yaml"

COMMON_CONTEXT_PARAMS = {"site", "site_id", "lang"}

COMPONENT_BY_RUNTIME_CONTRACT = {
    "public.route.show.v1": "PublicRouteResponse",
    "public.content.index.v1": "PublicContentIndexResponse",
    "public.content.show.v1": "PublicContentShowResponse",
    "public.content.by_path.v1": "PublicContentShowResponse",
    "public.menus.show.v1": "PublicMenuResponse",
    "public.menus.index.v1": "PublicMenuResponse",
    "public.media.show.v1": "PublicMediaResponse",
    "public.media.index.v1": "PublicMediaResponse",
    "public.search.index.v1": "PublicSearchResponse",
    "public.sale.channels.bootstrap.v1": "PublicSaleBootstrapResponse",
    "public.sale.cart.store.v1": "PublicSaleCartResponse",
    "public.sale.cart.show.v1": "PublicSaleCartResponse",
    "public.sale.cart.lines.store.v1": "PublicSaleCartLineMutationResponse",
    "public.sale.cart.lines.update.v1": "PublicSaleCartLineMutationResponse",
    "public.sale.cart.lines.delete.v1": "PublicSaleCartLineDeleteResponse",
    "public.sale.checkout.update.v1": "PublicSaleCheckoutUpdateResponse",
    "public.sale.cart.abandon.v1": "PublicSaleCartAbandonResponse",
    "public.sale.checkout.v1": "PublicSaleCheckoutResponse",
}

SCHEMA_COMPONENTS_REQUIRED = [
    "PublicMeta",
    "PublicApiError",
    "Pagination",
    "PublicRouteResponse",
    "PublicContentIndexResponse",
    "PublicContentShowResponse",
    "PublicMenuResponse",
    "PublicMediaResponse",
    "PublicSearchResponse",
    "PublicSaleChannel",
    "PublicSaleCartLine",
    "PublicSaleGuestIdentity",
    "PublicSaleAddress",
    "PublicSaleCart",
    "PublicSaleOrder",
    "PublicSaleBootstrapResponse",
    "PublicSaleCartResponse",
    "PublicSaleCartLineMutationResponse",
    "PublicSaleCartLineDeleteResponse",
    "PublicSaleCheckoutResponse",
]


def load_contracts() -> list[tuple[Path, dict[str, Any]]]:
    contracts: list[tuple[Path, dict[str, Any]]] = []
    for path in sorted(CONTRACT_DIR.glob("*.json")):
        with path.open("r", encoding="utf-8") as handle:
            data = json.load(handle)
        if not isinstance(data, dict):
            raise ValueError(f"{path.name}: le contrat racine doit être un objet JSON")
        contracts.append((path, data))
    return contracts


def normalize_schema(schema: Any) -> Any:
    """Nettoie les références documentaires locales non OpenAPI."""
    if isinstance(schema, dict):
        result: dict[str, Any] = {}
        for key, value in schema.items():
            if key == "$ref" and isinstance(value, str) and value.startswith("../admin-api-v1/error.v1.json"):
                result[key] = "#/components/schemas/PublicApiError"
            else:
                result[key] = normalize_schema(value)
        return result
    if isinstance(schema, list):
        return [normalize_schema(item) for item in schema]
    return schema


def schema_from_param(spec: dict[str, Any]) -> dict[str, Any]:
    schema: dict[str, Any] = {"type": spec.get("type", "string")}
    for key in ("enum", "minimum", "maximum", "pattern", "default", "format"):
        if key in spec:
            schema[key] = spec[key]
    return schema


def parameter(name: str, spec: dict[str, Any], location: str, *, force_required: bool = False) -> dict[str, Any]:
    return {
        "name": name,
        "in": location,
        "required": bool(force_required or spec.get("required", False) or location == "path"),
        "description": str(spec.get("description", "")).strip(),
        "schema": schema_from_param(spec),
    }


def operation_parameters(contract: dict[str, Any], path: str) -> list[dict[str, Any]]:
    params: list[dict[str, Any]] = []
    query_params = contract.get("query_params") or {}
    path_params = contract.get("path_params") or {}
    header_params = contract.get("headers") or {}
    if isinstance(header_params, dict):
        for name, spec in header_params.items():
            if not isinstance(spec, dict):
                continue
            params.append(parameter(str(name), spec, "header"))
    if isinstance(query_params, dict):
        for name, spec in query_params.items():
            if not isinstance(spec, dict):
                continue
            # Les paramètres communs site/site_id/lang sont inclus uniquement s'ils sont présents dans le contrat.
            # Les autres filtres contractuels sont conservés également.
            params.append(parameter(str(name), spec, "query"))
    path_names = set(re.findall(r"{([^}]+)}", path))
    if isinstance(path_params, dict):
        for name in sorted(path_names):
            spec = path_params.get(name, {})
            if not isinstance(spec, dict):
                spec = {}
            params.append(parameter(str(name), spec, "path", force_required=True))
    return params


def request_body(contract: dict[str, Any]) -> dict[str, Any] | None:
    body = contract.get("request_body")
    if not isinstance(body, dict):
        return None
    schema = body.get("schema")
    if not isinstance(schema, dict):
        return None
    content_type = str(body.get("content_type") or "application/json")
    return {
        "required": bool(body.get("required", False)),
        "content": {
            content_type: {
                "schema": normalize_schema(deepcopy(schema)),
            }
        },
    }


def extract_response_schema(contract: dict[str, Any], runtime_contract: str | None = None) -> dict[str, Any]:
    response = contract.get("response")
    if not isinstance(response, dict):
        return {"type": "object"}
    if isinstance(response.get("oneOf"), list):
        variants = response["oneOf"]
        selected = None
        if runtime_contract:
            for item in variants:
                if isinstance(item, dict) and item.get("contract") == runtime_contract:
                    selected = item
                    break
        if selected is None and variants:
            selected = variants[0]
        if isinstance(selected, dict) and isinstance(selected.get("schema"), dict):
            return normalize_schema(deepcopy(selected["schema"]))
        return {"type": "object"}
    if isinstance(response.get("schema"), dict):
        return normalize_schema(deepcopy(response["schema"]))
    return normalize_schema(deepcopy(response))


def extract_examples(contract: dict[str, Any], runtime_contract: str | None = None) -> dict[str, Any]:
    examples = contract.get("examples")
    if not isinstance(examples, dict):
        return {}
    if runtime_contract == "public.taxonomies.index.v1" and "index_response" in examples:
        return {"default": {"value": examples["index_response"]}}
    if runtime_contract == "public.taxonomies.show.v1" and "show_response" in examples:
        return {"default": {"value": examples["show_response"]}}
    if "response" in examples:
        return {"default": {"value": examples["response"]}}
    return {}


def response_object(schema_ref_or_schema: dict[str, Any], examples: dict[str, Any] | None = None, content_type: str = "application/json") -> dict[str, Any]:
    media: dict[str, Any] = {"schema": schema_ref_or_schema}
    if examples:
        media["examples"] = examples
    return {
        "description": "Réponse normalisée de l'API publique headless v1.",
        "content": {content_type: media},
    }


def error_response(status: int, description: str) -> dict[str, Any]:
    return {
        "description": description or "Erreur normalisée de l'API publique headless v1.",
        "content": {
            "application/json": {
                "schema": {"$ref": "#/components/schemas/PublicApiError"},
                "examples": {
                    "default": {
                        "value": {
                            "error": {
                                "code": "NOT_FOUND" if status == 404 else "VALIDATION_FAILED",
                                "message": description or "Erreur API publique.",
                                "details": {},
                            },
                            "meta": {"contract": "error.v1", "request_id": "20260604120000-abc"},
                        }
                    }
                },
            }
        },
    }


def collect_operations(contracts: list[tuple[Path, dict[str, Any]]]) -> list[dict[str, Any]]:
    operations: list[dict[str, Any]] = []
    for _, contract in contracts:
        if isinstance(contract.get("methods"), list):
            for method_item in contract["methods"]:
                if not isinstance(method_item, dict):
                    continue
                method = str(method_item.get("method", "GET")).upper()
                path = str(method_item.get("path", ""))
                if not (path == "/api/v1" or path.startswith("/api/v1/")):
                    continue
                operations.append({
                    "method": method,
                    "path": path,
                    "contract": contract,
                    "runtime_contract": method_item.get("runtime_contract") or contract.get("contract"),
                })
            continue
        method = str(contract.get("method", "GET")).upper()
        paths: list[str] = []
        if isinstance(contract.get("path"), str):
            paths.append(contract["path"])
        if isinstance(contract.get("paths"), list):
            paths.extend(str(item) for item in contract["paths"] if isinstance(item, str))
        for path in paths:
            if path == "/api/v1" or path.startswith("/api/v1/"):
                operations.append({
                    "method": method,
                    "path": path,
                    "contract": contract,
                    "runtime_contract": contract.get("contract"),
                })
    return sorted(operations, key=lambda item: (item["path"], item["method"], str(item["runtime_contract"])))


def base_components() -> dict[str, Any]:
    return {
        "securitySchemes": {
            "BearerAuth": {
                "type": "http",
                "scheme": "bearer",
                "bearerFormat": "Opaque token",
                "description": "Token stateless optionnel selon configuration APP_PUBLIC_API_AUTH_ENABLED.",
            }
        },
        "schemas": {
            "PublicMeta": {
                "type": "object",
                "required": ["contract", "request_id"],
                "properties": {
                    "contract": {"type": "string"},
                    "request_id": {"type": "string"},
                    "site_id": {"type": "integer"},
                    "language_code": {"type": "string"},
                    "source": {"type": "string"},
                },
                "additionalProperties": True,
            },
            "PublicApiError": {
                "type": "object",
                "required": ["error", "meta"],
                "properties": {
                    "error": {
                        "type": "object",
                        "required": ["code", "message"],
                        "properties": {
                            "code": {"type": "string"},
                            "message": {"type": "string"},
                            "details": {"type": "object", "additionalProperties": True},
                        },
                        "additionalProperties": True,
                    },
                    "meta": {"$ref": "#/components/schemas/PublicMeta"},
                },
                "additionalProperties": False,
            },
            "Pagination": {
                "type": "object",
                "required": ["total", "limit", "offset", "has_more"],
                "properties": {
                    "total": {"type": "integer", "minimum": 0},
                    "limit": {"type": "integer", "minimum": 1},
                    "offset": {"type": "integer", "minimum": 0},
                    "has_more": {"type": "boolean"},
                },
                "additionalProperties": True,
            },
            "PublicSaleChannel": {
                "type": "object",
                "required": ["channel_id", "site_id", "code", "type", "name", "default_currency", "default_locale", "currency", "default_language", "tax_mode"],
                "properties": {
                    "channel_id": {"type": "integer", "minimum": 1},
                    "site_id": {"type": "integer", "minimum": 1},
                    "code": {"type": "string"},
                    "type": {"type": "string", "enum": ["storefront", "pos", "admin", "partner"]},
                    "name": {"type": "string"},
                    "default_currency": {"type": "string"},
                    "default_locale": {"type": "string"},
                    "currency": {"type": "string"},
                    "default_language": {"type": "string"},
                    "tax_mode": {"type": "string"},
                },
                "additionalProperties": True,
            },
            "PublicSaleCartLine": {
                "type": "object",
                "required": ["id", "business_variant_id", "quantity", "unit_price_minor", "currency", "line_total_minor"],
                "properties": {
                    "sellable_id": {"type": "integer"},
                    "id": {"type": "integer"},
                    "business_product_id": {"type": "integer"},
                    "business_variant_id": {"type": "integer"},
                    "sku": {"type": ["string", "null"]},
                    "barcode": {"type": ["string", "null"]},
                    "product_name": {"type": "string"},
                    "variant_name": {"type": ["string", "null"]},
                    "quantity": {"type": "integer", "minimum": 0},
                    "unit_price_minor": {"type": "integer"},
                    "regular_unit_price_minor": {"type": "integer"},
                    "currency": {"type": "string"},
                    "tax_rate_basis_points": {"type": "integer"},
                    "tax_class_code": {"type": "string"},
                    "tax_included": {"type": "boolean"},
                    "line_subtotal_minor": {"type": "integer"},
                    "line_discount_minor": {"type": "integer"},
                    "line_tax_minor": {"type": "integer"},
                    "line_total_minor": {"type": "integer"},
                },
                "additionalProperties": True,
            },
            "PublicSaleGuestIdentity": {
                "type": "object",
                "required": ["email", "first_name", "last_name"],
                "properties": {
                    "email": {"type": "string", "format": "email", "maxLength": 254},
                    "first_name": {"type": "string", "minLength": 1, "maxLength": 100},
                    "last_name": {"type": "string", "minLength": 1, "maxLength": 100},
                    "phone": {"type": ["string", "null"], "maxLength": 40},
                },
                "additionalProperties": False,
            },
            "PublicSaleAddress": {
                "type": "object",
                "required": ["line1", "postal_code", "city", "country_code"],
                "properties": {
                    "line1": {"type": "string", "minLength": 1},
                    "line2": {"type": ["string", "null"]},
                    "postal_code": {"type": "string", "minLength": 1},
                    "city": {"type": "string", "minLength": 1},
                    "region": {"type": ["string", "null"]},
                    "country_code": {"type": "string", "pattern": "^[A-Z]{2}$"},
                },
                "additionalProperties": False,
            },
            "PublicSaleCart": {
                "type": "object",
                "required": ["id", "status", "currency", "subtotal_minor", "discount_total_minor", "tax_total_minor", "shipping_total_minor", "grand_total_minor"],
                "properties": {
                    "id": {"type": "integer"},
                    "token": {"type": "string"},
                    "status": {"type": "string"},
                    "currency": {"type": "string"},
                    "subtotal_minor": {"type": "integer"},
                    "discount_total_minor": {"type": "integer"},
                    "tax_total_minor": {"type": "integer"},
                    "shipping_total_minor": {"type": "integer"},
                    "grand_total_minor": {"type": "integer"},
                    "expires_at": {"type": ["string", "null"]},
                    "checkout_step": {"type": "string"},
                    "identity": {"$ref": "#/components/schemas/PublicSaleGuestIdentity"},
                    "billing_address": {"$ref": "#/components/schemas/PublicSaleAddress"},
                    "shipping_address": {"$ref": "#/components/schemas/PublicSaleAddress"},
                    "shipping_method": {"type": "object", "additionalProperties": True},
                    "payment_method": {"type": "object", "additionalProperties": True},
                    "terms_accepted": {"type": "boolean"},
                    "marketing_consent": {"type": ["boolean", "null"]},
                    "lines": {"type": "array", "items": {"$ref": "#/components/schemas/PublicSaleCartLine"}},
                },
                "additionalProperties": True,
            },
            "PublicSaleOrder": {
                "type": "object",
                "required": ["id", "order_number", "source", "status", "payment_status", "currency", "grand_total_minor"],
                "properties": {
                    "id": {"type": "integer"},
                    "order_number": {"type": "string"},
                    "source": {"type": "string"},
                    "status": {"type": "string"},
                    "payment_status": {"type": "string"},
                    "currency": {"type": "string"},
                    "subtotal_minor": {"type": "integer"},
                    "discount_total_minor": {"type": "integer"},
                    "tax_total_minor": {"type": "integer"},
                    "shipping_total_minor": {"type": "integer"},
                    "grand_total_minor": {"type": "integer"},
                    "lines": {"type": "array", "items": {"$ref": "#/components/schemas/PublicSaleCartLine"}},
                    "placed_at": {"type": ["string", "null"]},
                },
                "additionalProperties": True,
            },
        },
    }



def yaml_key(key: Any) -> str:
    """Retourne une clé YAML stable et lisible sans dépendance externe."""
    text = str(key)
    if re.match(r"^[A-Za-z_][A-Za-z0-9_-]*$", text):
        return text
    return json.dumps(text, ensure_ascii=False)


def yaml_scalar(value: Any) -> str:
    """Sérialise un scalaire YAML en restant compatible avec JSON/YAML 1.2."""
    if value is None:
        return "null"
    if isinstance(value, bool):
        return "true" if value else "false"
    if isinstance(value, (int, float)) and not isinstance(value, bool):
        return str(value)
    return json.dumps(str(value), ensure_ascii=False)


def yaml_dump(value: Any, indent: int = 0) -> str:
    """Sérialiseur YAML déterministe minimal pour le contrat OpenAPI généré.

    Il couvre volontairement les types produits par build_openapi(): dict, list,
    str, int, float, bool et null. L'ordre d'insertion des dicts est conservé pour
    garder la sortie stable et alignée sur openapi.v1.json.
    """
    space = " " * indent
    if isinstance(value, dict):
        if not value:
            return space + "{}"
        lines: list[str] = []
        for raw_key, item in value.items():
            key = yaml_key(raw_key)
            if isinstance(item, (dict, list)):
                if not item:
                    lines.append(f"{space}{key}: {{}}" if isinstance(item, dict) else f"{space}{key}: []")
                else:
                    lines.append(f"{space}{key}:")
                    lines.append(yaml_dump(item, indent + 2))
            else:
                lines.append(f"{space}{key}: {yaml_scalar(item)}")
        return "\n".join(lines)
    if isinstance(value, list):
        if not value:
            return space + "[]"
        lines = []
        for item in value:
            if isinstance(item, (dict, list)):
                if not item:
                    lines.append(f"{space}- {{}}" if isinstance(item, dict) else f"{space}- []")
                else:
                    lines.append(f"{space}-")
                    lines.append(yaml_dump(item, indent + 2))
            else:
                lines.append(f"{space}- {yaml_scalar(item)}")
        return "\n".join(lines)
    return space + yaml_scalar(value)


def serialize_json(openapi: dict[str, Any]) -> str:
    return json.dumps(openapi, ensure_ascii=False, indent=2) + "\n"


def serialize_yaml(openapi: dict[str, Any]) -> str:
    return yaml_dump(openapi) + "\n"

def build_openapi(contracts: list[tuple[Path, dict[str, Any]]]) -> dict[str, Any]:
    operations = collect_operations(contracts)
    components = base_components()
    component_schemas = components["schemas"]

    # Alimente les schémas réutilisables à partir des contrats eux-mêmes.
    for operation in operations:
        runtime_contract = str(operation.get("runtime_contract") or "")
        component_name = COMPONENT_BY_RUNTIME_CONTRACT.get(runtime_contract)
        if component_name and component_name not in component_schemas:
            component_schemas[component_name] = extract_response_schema(operation["contract"], runtime_contract)

    # Garde un fallback explicite si un contrat futur n'alimente pas encore un composant requis.
    for name in SCHEMA_COMPONENTS_REQUIRED:
        component_schemas.setdefault(name, {"type": "object", "additionalProperties": True})

    paths: dict[str, Any] = {}
    for operation in operations:
        contract = operation["contract"]
        method = operation["method"].lower()
        path = operation["path"]
        runtime_contract = str(operation.get("runtime_contract") or contract.get("contract") or "")
        if "/admin" in path or not (path == "/api/v1" or path.startswith("/api/v1/")):
            continue
        component_name = COMPONENT_BY_RUNTIME_CONTRACT.get(runtime_contract)
        schema = {"$ref": f"#/components/schemas/{component_name}"} if component_name else extract_response_schema(contract, runtime_contract)
        response = contract.get("response") if isinstance(contract.get("response"), dict) else {}
        success_status = str(response.get("status") if isinstance(response.get("status"), int) else 200)
        content_type = str(response.get("content_type") or "application/json")
        responses: dict[str, Any] = {
            success_status: response_object(schema, extract_examples(contract, runtime_contract), content_type),
        }
        for err in contract.get("errors", []):
            if isinstance(err, dict) and isinstance(err.get("status"), int):
                responses[str(err["status"])] = error_response(int(err["status"]), str(err.get("description", "")))
        security_description = str(contract.get("security") or "").lower()
        operation_security = [] if "no bearer" in security_description or "public endpoint" in security_description else [{"BearerAuth": []}]
        operation_item: dict[str, Any] = {
            "operationId": operation_id(method, path, runtime_contract),
            "summary": str(contract.get("summary") or runtime_contract),
            "description": str(contract.get("description") or contract.get("summary") or ""),
            "tags": ["Headless v1"],
            "security": operation_security,
            "parameters": operation_parameters(contract, path),
            "responses": responses,
        }
        body = request_body(contract)
        if body is not None:
            operation_item["requestBody"] = body
        paths.setdefault(path, {})[method] = operation_item

    return {
        "openapi": "3.1.0",
        "info": {
            "title": "DEC CMS Public Headless API",
            "version": "1.0.0",
            "description": "OpenAPI généré depuis docs/reference/contracts/headless-v1/. Surface limitée à /api/v1/*.",
        },
        "servers": [{"url": "/", "description": "Même origine que le front public."}],
        "tags": [{"name": "Headless v1", "description": "API publique headless v1."}],
        "paths": paths,
        "components": components,
    }


def operation_id(method: str, path: str, runtime_contract: str) -> str:
    base = runtime_contract or path
    base = re.sub(r"[^A-Za-z0-9]+", "_", base).strip("_")
    return f"{method.lower()}_{base}"


def main() -> int:
    try:
        contracts = load_contracts()
        openapi = build_openapi(contracts)
        OUTPUT_JSON_PATH.parent.mkdir(parents=True, exist_ok=True)
        REFERENCE_JSON_PATH.parent.mkdir(parents=True, exist_ok=True)
        OUTPUT_JSON_PATH.write_text(serialize_json(openapi), encoding="utf-8")
        OUTPUT_YAML_PATH.write_text(serialize_yaml(openapi), encoding="utf-8")
        REFERENCE_JSON_PATH.write_text(serialize_json(openapi), encoding="utf-8")
        REFERENCE_YAML_PATH.write_text(serialize_yaml(openapi), encoding="utf-8")
        path_count = len(openapi.get("paths", {}))
        schema_count = len(openapi.get("components", {}).get("schemas", {}))
        operation_count = sum(len(item) for item in openapi.get("paths", {}).values() if isinstance(item, dict))
        print("OK: OpenAPI headless v1 généré")
        print(f"- JSON: {OUTPUT_JSON_PATH.relative_to(ROOT)}")
        print(f"- YAML: {OUTPUT_YAML_PATH.relative_to(ROOT)}")
        print(f"- Reference JSON: {REFERENCE_JSON_PATH.relative_to(ROOT)}")
        print(f"- Reference YAML: {REFERENCE_YAML_PATH.relative_to(ROOT)}")
        print(f"Résumé: {path_count} chemins, {schema_count} schémas, {operation_count} opérations")
        return 0
    except Exception as exc:  # noqa: BLE001 - script CLI, message clair attendu.
        print(f"ERREUR: génération OpenAPI headless v1 impossible: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
