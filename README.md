# Advertisement MCP

![Demo](assets/demo.gif)

## Start

```sh
docker compose up -d && docker compose --profile ingest up
```

## MCP

`claude plugin install ads --marketplace devastion/ads-mcp --config api_key=ghk_LocalDevOnlyNotForProd000000000000000000`

```json
{
  "mcpServers": {
    "ads": {
      "url": "http://localhost:8000/api/mcp",
      "headers": {
        "Authorization": "Bearer ghk_LocalDevOnlyNotForProd000000000000000000"
      }
    }
  }
}
```
