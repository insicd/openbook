# REST API draft

> [Italiano](README.it.md) · [OpenAPI specification](openapi.json)

The initial prototype provides `GET /rest/v1/persons/{personId}`, using the
person UUID within the instance. It returns a Person object directly.

It is available only in the local and testing environments. Authentication
is not enforced in this prototype, including for remote profiles. It is not
a production API. Refresh the route cache after installing the changes.

Example with the development server:

```sh
curl -i -H 'Accept: application/json' \
  'http://127.0.0.1:8000/rest/v1/persons/PERSON_UUID'
```

Responses include ETag and support If-None-Match with HTTP 304. The request
limit is 60 per minute per client. Errors use application/problem+json.
Remote profiles use data already available to the instance; no remote fetch
is triggered. Locally suspended profiles and unavailable resources return 404.

Authentication, dedicated CORS support and Last-Modified are not implemented
in this initial prototype. If-Modified-Since alone does not produce 304.

The following endpoints return a direct array of PersonReference objects for
the person specified in the path:

- `GET /rest/v1/persons/{personId}/followers`
- `GET /rest/v1/persons/{personId}/following`

Each reference contains personId, personUsername, personDomain and personName,
plus personAvatar when available. Use the personId for the complete profile.
Only accepted follows are included. Remote lists may be incomplete and include
only people already known to this instance.

Use `limit` (default 20, maximum 100) and `after` (the last received personId).
The initial ordering is `sort=personId`, with `direction=asc` (default) or
`desc`. A missing reference does not reset pagination. The optional Link header
with `rel="next"` provides the next page URL. Invalid parameters return HTTP 422.
