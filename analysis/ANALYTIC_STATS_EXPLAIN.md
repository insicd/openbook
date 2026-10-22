# ANALYTIC_STATS — piani EXPLAIN ANALYZE

Misure locali del 22 ottobre 2026. SQL e perimetro sono descritti in [ANALYTIC_STATS](ANALYTIC_STATS.md#query-e-verifica-explain-analyze).

Per ogni forma: tre esecuzioni consecutive, tempi del nodo radice e tempi wall del client PHP; sotto è conservato integralmente il piano della terza esecuzione. I tempi non sono sommabili tra nodi annidati.

Ambiente riportato dal server: `{"version": "26.7.0", "product": "Homebrew", "timezone": "+00:00", "observed_at": "2026-10-22 15:02:57"}`.

## Q00 — Actor per dominio: query esplorativa originale

Radice ms: `[33.5, 11.3, 11.7]`. Wall ms: `[34.14, 11.66, 12.03]`. Righe risultato: `2979`.

```text
-> Sort: C DESC, actors.domain  (actual time=11.6..11.7 rows=2979 loops=1)
    -> Table scan on <temporary>  (actual time=8.62..8.76 rows=2979 loops=1)
        -> Aggregate using temporary table  (actual time=8.62..8.62 rows=2979 loops=1)
            -> Covering index scan on actors using actors_is_local_domain_index  (cost=4433 rows=34516) (actual time=0.00454..2.62 rows=33045 loops=1)
```

## Q01 — Actor pubblici per dominio e tipo

Radice ms: `[41.5, 16.9, 16.5]`. Wall ms: `[42.68, 17.37, 16.89]`. Righe risultato: `1213`.

```text
-> Sort: actors_count DESC, eligible_actors.domain, eligible_actors.`type`, eligible_actors.is_local  (actual time=16.5..16.5 rows=1213 loops=1)
    -> Table scan on <temporary>  (actual time=14.5..14.5 rows=1213 loops=1)
        -> Aggregate using temporary table  (actual time=14.5..14.5 rows=1213 loops=1)
            -> Nested loop antijoin  (cost=3846 rows=1) (actual time=0.0103..11.2 rows=6891 loops=1)
                -> Filter: ((a.indexable = 1) and (a.discoverable = 1) and (a.remote_suspended = 0) and (a.`status` = 'active') and (a.deleted_at is null) and (a.`type` <> 'application'))  (cost=3845 rows=1) (actual time=0.00862..9.58 rows=6891 loops=1)
                    -> Table scan on a  (cost=3845 rows=34516) (actual time=0.00812..8.41 rows=33045 loops=1)
                -> Single-row covering index lookup on b using domain_blocks_domain_unique (domain = a.domain)  (cost=1.1 rows=1) (actual time=182e-6..182e-6 rows=0 loops=6891)
```

## Q02 — Top 10 hashtag nel corpus conservato

Radice ms: `[1642, 1287, 562]`. Wall ms: `[1648.48, 1291.67, 565.36]`. Righe risultato: `10`.

```text
-> Limit: 10 row(s)  (actual time=562..562 rows=10 loops=1)
    -> Sort: post_count DESC, h.`name`, h.id, limit input to 10 row(s) per chunk  (actual time=562..562 rows=10 loops=1)
        -> Table scan on <temporary>  (actual time=554..556 rows=14857 loops=1)
            -> Aggregate using temporary table  (actual time=554..554 rows=14857 loops=1)
                -> Nested loop inner join  (cost=1244 rows=34.4) (actual time=0.597..525 rows=67775 loops=1)
                    -> Nested loop inner join  (cost=1220 rows=68.9) (actual time=0.594..428 rows=67775 loops=1)
                        -> Nested loop antijoin  (cost=1209 rows=12.1) (actual time=0.582..373 rows=20084 loops=1)
                            -> Nested loop inner join  (cost=1207 rows=12.1) (actual time=0.58..369 rows=20084 loops=1)
                                -> Filter: ((p.conversation_id is null) and ((p.community_id is null) or exists(select #5)))  (cost=1117 rows=243) (actual time=0.571..345 rows=48751 loops=1)
                                    -> Index lookup on p using posts_popular_remote_actor_index (status = 'published', visibility = 'public'), with index condition: ((p.published_at >= TIMESTAMP'2024-10-22 15:00:00') and (p.published_at < TIMESTAMP'2026-10-22 15:00:00'))  (cost=1117 rows=21855) (actual time=0.571..344 rows=48751 loops=1)
                                    -> Select #5 (subquery in condition; dependent)
                                        -> Limit: 1 row(s)  (cost=0.35 rows=1) (actual time=0.00592..0.00596 rows=1 loops=1)
                                            -> Filter: (c.is_private = 0)  (cost=0.35 rows=1) (actual time=0.00579..0.00579 rows=1 loops=1)
                                                -> Single-row index lookup on c using PRIMARY (id = p.community_id)  (cost=0.35 rows=1) (actual time=0.00554..0.00554 rows=1 loops=1)
                                -> Filter: ((a.indexable = 1) and (a.discoverable = 1) and (a.remote_suspended = 0) and (a.`status` = 'active') and (a.deleted_at is null) and (a.`type` <> 'application'))  (cost=0.269 rows=0.05) (actual time=412e-6..429e-6 rows=0.412 loops=48751)
                                    -> Single-row index lookup on a using PRIMARY (id = p.actor_id)  (cost=0.269 rows=1) (actual time=303e-6..317e-6 rows=1 loops=48751)
                            -> Single-row index lookup on <subquery4> using <auto_distinct_key> (domain = a.domain)  (cost=1.37..1.37 rows=1) (actual time=142e-6..142e-6 rows=0 loops=20084)
                                -> Materialize with deduplication  (cost=1.33..1.33 rows=1) (actual time=0.00221..0.00221 rows=0 loops=1)
                                    -> Filter: (b.domain is not null)  (cost=1.1 rows=1) (actual time=0.00154..0.00154 rows=0 loops=1)
                                        -> Covering index scan on b using domain_blocks_domain_unique  (cost=1.1 rows=1) (actual time=0.00146..0.00146 rows=0 loops=1)
                        -> Covering index lookup on ph using PRIMARY (post_id = p.id)  (cost=0.375 rows=5.67) (actual time=0.00226..0.00264 rows=3.37 loops=20084)
                    -> Filter: (h.`name` <> '')  (cost=0.251 rows=0.5) (actual time=0.00132..0.00136 rows=1 loops=67775)
                        -> Single-row index lookup on h using PRIMARY (id = ph.hashtag_id)  (cost=0.251 rows=1) (actual time=0.00126..0.00127 rows=1 loops=67775)
```

## Q03 — Serie settimanale di 10 hashtag selezionati

Radice ms: `[193, 202, 211]`. Wall ms: `[196.84, 204.72, 213.73]`. Righe risultato: `100`.

```text
-> Sort: week_start, ph.hashtag_id  (actual time=211..211 rows=100 loops=1)
    -> Table scan on <temporary>  (actual time=211..211 rows=100 loops=1)
        -> Aggregate using temporary table  (actual time=211..211 rows=100 loops=1)
            -> Nested loop inner join  (cost=1220 rows=15.1) (actual time=3.13..208 rows=9104 loops=1)
                -> Nested loop antijoin  (cost=1209 rows=12.1) (actual time=0.0983..149 rows=20084 loops=1)
                    -> Nested loop inner join  (cost=1207 rows=12.1) (actual time=0.0955..145 rows=20084 loops=1)
                        -> Filter: ((p.conversation_id is null) and ((p.community_id is null) or exists(select #5)))  (cost=1117 rows=243) (actual time=0.0883..122 rows=48751 loops=1)
                            -> Index lookup on p using posts_popular_remote_actor_index (status = 'published', visibility = 'public'), with index condition: ((p.published_at >= TIMESTAMP'2024-10-22 15:00:00') and (p.published_at < TIMESTAMP'2026-10-22 15:00:00'))  (cost=1117 rows=21855) (actual time=0.088..120 rows=48751 loops=1)
                            -> Select #5 (subquery in condition; dependent)
                                -> Limit: 1 row(s)  (cost=0.35 rows=1) (actual time=0.00679..0.00679 rows=1 loops=1)
                                    -> Filter: (c.is_private = 0)  (cost=0.35 rows=1) (actual time=0.00671..0.00671 rows=1 loops=1)
                                        -> Single-row index lookup on c using PRIMARY (id = p.community_id)  (cost=0.35 rows=1) (actual time=0.00658..0.00658 rows=1 loops=1)
                        -> Filter: ((a.indexable = 1) and (a.discoverable = 1) and (a.remote_suspended = 0) and (a.`status` = 'active') and (a.deleted_at is null) and (a.`type` <> 'application'))  (cost=0.269 rows=0.05) (actual time=408e-6..425e-6 rows=0.412 loops=48751)
                            -> Single-row index lookup on a using PRIMARY (id = p.actor_id)  (cost=0.269 rows=1) (actual time=305e-6..319e-6 rows=1 loops=48751)
                    -> Single-row index lookup on <subquery4> using <auto_distinct_key> (domain = a.domain)  (cost=1.42..1.42 rows=1) (actual time=142e-6..142e-6 rows=0 loops=20084)
                        -> Materialize with deduplication  (cost=1.33..1.33 rows=1) (actual time=0.00217..0.00217 rows=0 loops=1)
                            -> Filter: (b.domain is not null)  (cost=1.1 rows=1) (actual time=0.00167..0.00167 rows=0 loops=1)
                                -> Covering index scan on b using domain_blocks_domain_unique  (cost=1.1 rows=1) (actual time=0.00162..0.00162 rows=0 loops=1)
                -> Filter: (ph.hashtag_id in ('019fc806-7d4e-715b-8dd2-85520afba1e9','019fc832-28cb-736b-959a-869dc994435a','019fc806-7d50-72ce-9acf-d39093bc7ed8','019fd6cd-3552-70ba-8784-d43ef9f45de6','019ff7b5-9000-7299-97c8-89786db1f3cd','019fd0e4-ac6c-7228-a881-d196daa2002a','019fda3c-1c16-72c4-88f4-63649c606bb1','019fd0e4-ac49-712f-9eb4-42367b63a376','019ff81b-825d-712d-aad9-708c115668d7','019fd1c5-9092-7342-8945-ce11382f7f39'))  (cost=0.338 rows=1.24) (actual time=0.00241..0.00286 rows=0.453 loops=20084)
                    -> Covering index lookup on ph using PRIMARY (post_id = p.id)  (cost=0.338 rows=5.67) (actual time=0.00206..0.0024 rows=3.37 loops=20084)
```

## Q04 — Denominatore settimanale e volume locale/remoto

Radice ms: `[122, 107, 109]`. Wall ms: `[124.93, 107.91, 109.67]`. Righe risultato: `68`.

```text
-> Sort: week_start, public_posts.is_local  (actual time=109..109 rows=68 loops=1)
    -> Table scan on <temporary>  (actual time=109..109 rows=68 loops=1)
        -> Aggregate using temporary table  (actual time=109..109 rows=68 loops=1)
            -> Nested loop antijoin  (cost=1209 rows=12.1) (actual time=0.0405..105 rows=20084 loops=1)
                -> Nested loop inner join  (cost=1207 rows=12.1) (actual time=0.0381..101 rows=20084 loops=1)
                    -> Filter: ((p.conversation_id is null) and ((p.community_id is null) or exists(select #5)))  (cost=1117 rows=243) (actual time=0.0316..78.3 rows=48751 loops=1)
                        -> Index lookup on p using posts_popular_remote_actor_index (status = 'published', visibility = 'public'), with index condition: ((p.published_at >= TIMESTAMP'2024-10-22 15:00:00') and (p.published_at < TIMESTAMP'2026-10-22 15:00:00'))  (cost=1117 rows=21855) (actual time=0.0315..76.7 rows=48751 loops=1)
                        -> Select #5 (subquery in condition; dependent)
                            -> Limit: 1 row(s)  (cost=0.35 rows=1) (actual time=0.00404..0.00404 rows=1 loops=1)
                                -> Filter: (c.is_private = 0)  (cost=0.35 rows=1) (actual time=0.00392..0.00392 rows=1 loops=1)
                                    -> Single-row index lookup on c using PRIMARY (id = p.community_id)  (cost=0.35 rows=1) (actual time=0.00392..0.00392 rows=1 loops=1)
                    -> Filter: ((a.indexable = 1) and (a.discoverable = 1) and (a.remote_suspended = 0) and (a.`status` = 'active') and (a.deleted_at is null) and (a.`type` <> 'application'))  (cost=0.269 rows=0.05) (actual time=403e-6..420e-6 rows=0.412 loops=48751)
                        -> Single-row index lookup on a using PRIMARY (id = p.actor_id)  (cost=0.269 rows=1) (actual time=300e-6..314e-6 rows=1 loops=48751)
                -> Single-row index lookup on <subquery4> using <auto_distinct_key> (domain = a.domain)  (cost=2.64..2.64 rows=1) (actual time=142e-6..142e-6 rows=0 loops=20084)
                    -> Materialize with deduplication  (cost=1.33..1.33 rows=1) (actual time=0.00179..0.00179 rows=0 loops=1)
                        -> Filter: (b.domain is not null)  (cost=1.1 rows=1) (actual time=0.00133..0.00133 rows=0 loops=1)
                            -> Covering index scan on b using domain_blocks_domain_unique  (cost=1.1 rows=1) (actual time=0.00125..0.00125 rows=0 loops=1)
```

## Q05 — Lingue di post ed eventi, separate per tipo

Radice ms: `[167, 178, 195]`. Wall ms: `[171.27, 181.61, 198.27]`. Righe risultato: `114`.

```text
-> Sort: uses_by_language.kind, uses_by_language.week_start, uses_by_language.`language`, uses_by_language.is_local  (actual time=195..195 rows=114 loops=1)
    -> Table scan on <temporary>  (actual time=195..195 rows=114 loops=1)
        -> Aggregate using temporary table  (actual time=195..195 rows=114 loops=1)
            -> Table scan on uses_by_language  (cost=1232..1235 rows=12.2) (actual time=188..189 rows=20160 loops=1)
                -> Union all materialize  (cost=1232..1232 rows=12.2) (actual time=188..188 rows=20160 loops=1)
                    -> Nested loop antijoin  (cost=1209 rows=12.1) (actual time=0.299..185 rows=20084 loops=1)
                        -> Nested loop inner join  (cost=1207 rows=12.1) (actual time=0.297..181 rows=20084 loops=1)
                            -> Filter: ((p.conversation_id is null) and ((p.community_id is null) or exists(select #6)))  (cost=1117 rows=243) (actual time=0.289..157 rows=48751 loops=1)
                                -> Index lookup on p using posts_popular_remote_actor_index (status = 'published', visibility = 'public'), with index condition: ((p.published_at >= TIMESTAMP'2024-10-22 15:00:00') and (p.published_at < TIMESTAMP'2026-10-22 15:00:00'))  (cost=1117 rows=21855) (actual time=0.289..156 rows=48751 loops=1)
                                -> Select #6 (subquery in condition; dependent)
                                    -> Limit: 1 row(s)  (cost=0.35 rows=1) (actual time=0.00413..0.00417 rows=1 loops=1)
                                        -> Filter: (c.is_private = 0)  (cost=0.35 rows=1) (actual time=0.00408..0.00408 rows=1 loops=1)
                                            -> Single-row index lookup on c using PRIMARY (id = p.community_id)  (cost=0.35 rows=1) (actual time=0.00396..0.00396 rows=1 loops=1)
                            -> Filter: ((a.indexable = 1) and (a.discoverable = 1) and (a.remote_suspended = 0) and (a.`status` = 'active') and (a.deleted_at is null) and (a.`type` <> 'application'))  (cost=0.269 rows=0.05) (actual time=412e-6..428e-6 rows=0.412 loops=48751)
                                -> Single-row index lookup on a using PRIMARY (id = p.actor_id)  (cost=0.269 rows=1) (actual time=306e-6..320e-6 rows=1 loops=48751)
                        -> Single-row index lookup on <subquery5> using <auto_distinct_key> (domain = a.domain)  (cost=2.64..2.64 rows=1) (actual time=144e-6..144e-6 rows=0 loops=20084)
                            -> Materialize with deduplication  (cost=1.33..1.33 rows=1) (actual time=0.00154..0.00154 rows=0 loops=1)
                                -> Filter: (b.domain is not null)  (cost=1.1 rows=1) (actual time=0.00129..0.00129 rows=0 loops=1)
                                    -> Covering index scan on b using domain_blocks_domain_unique  (cost=1.1 rows=1) (actual time=0.00121..0.00121 rows=0 loops=1)
                    -> Nested loop antijoin  (cost=20.1 rows=0.0811) (actual time=0.0203..0.175 rows=76 loops=1)
                        -> Nested loop inner join  (cost=20 rows=0.0811) (actual time=0.0183..0.16 rows=76 loops=1)
                            -> Filter: ((e.visibility = 'public') and (e.deleted_at is null) and (e.`status` in ('scheduled','tentative','postponed')) and (e.published_at >= TIMESTAMP'2024-10-22 15:00:00') and (e.published_at < TIMESTAMP'2026-10-22 15:00:00') and (e.actor_id is not null))  (cost=19.4 rows=1.62) (actual time=0.0122..0.0754 rows=145 loops=1)
                                -> Table scan on e  (cost=19.4 rows=149) (actual time=0.0105..0.0492 rows=151 loops=1)
                            -> Filter: ((a.indexable = 1) and (a.discoverable = 1) and (a.remote_suspended = 0) and (a.`status` = 'active') and (a.deleted_at is null) and (a.`type` <> 'application'))  (cost=0.273 rows=0.05) (actual time=504e-6..524e-6 rows=0.524 loops=145)
                                -> Single-row index lookup on a using PRIMARY (id = e.actor_id)  (cost=0.273 rows=1) (actual time=393e-6..406e-6 rows=1 loops=145)
                        -> Single-row covering index lookup on b using domain_blocks_domain_unique (domain = a.domain)  (cost=2.23 rows=1) (actual time=137e-6..137e-6 rows=0 loops=76)
```

## Q06 — Scambi tra domini: risposte al destinatario effettivo

Radice ms: `[803, 112, 102]`. Wall ms: `[804.87, 116.03, 103.33]`. Righe risultato: `1361`.

```text
-> Sort: week_start, replies DESC, reply_edges.source_domain, reply_edges.target_domain  (actual time=102..102 rows=1361 loops=1)
    -> Stream results  (actual time=97.8..98.8 rows=1361 loops=1)
        -> Group aggregate: count(0), count(distinct comments.post_id)  (actual time=97.8..98.5 rows=1361 loops=1)
            -> Sort: week_start, reply_edges.source_domain, reply_edges.target_domain  (actual time=97.8..97.9 rows=2119 loops=1)
                -> Stream results  (cost=2720 rows=0.144) (actual time=0.0592..90.1 rows=2119 loops=1)
                    -> Filter: (a.domain <> (case when (c.parent_comment_id is null) then a.domain else a.domain end))  (cost=2720 rows=0.144) (actual time=0.0582..89.2 rows=2119 loops=1)
                        -> Nested loop left join  (cost=2720 rows=0.144) (actual time=0.0578..88.8 rows=3832 loops=1)
                            -> Filter: ((c.parent_comment_id is null) or (c.id is not null))  (cost=2720 rows=0.144) (actual time=0.057..86.3 rows=3832 loops=1)
                                -> Nested loop left join  (cost=2720 rows=0.144) (actual time=0.0567..86.1 rows=4825 loops=1)
                                    -> Nested loop antijoin  (cost=2720 rows=0.144) (actual time=0.0558..74.2 rows=4825 loops=1)
                                        -> Nested loop inner join  (cost=2720 rows=0.144) (actual time=0.0551..72.9 rows=4825 loops=1)
                                            -> Nested loop inner join  (cost=2719 rows=2.89) (actual time=0.0523..63.2 rows=7575 loops=1)
                                                -> Nested loop antijoin  (cost=2698 rows=57.8) (actual time=0.0308..50.1 rows=7641 loops=1)
                                                    -> Nested loop inner join  (cost=2687 rows=57.8) (actual time=0.0288..48.4 rows=7641 loops=1)
                                                        -> Filter: ((c.created_at >= TIMESTAMP'2024-10-22 15:00:00') and (c.created_at < TIMESTAMP'2026-10-22 15:00:00') and (c.`status` = 'published'))  (cost=2281 rows=1155) (actual time=0.0158..6.72 rows=25098 loops=1)
                                                            -> Table scan on c  (cost=2281 rows=20795) (actual time=0.0152..4.17 rows=25268 loops=1)
                                                        -> Filter: ((a.indexable = 1) and (a.discoverable = 1) and (a.remote_suspended = 0) and (a.`status` = 'active') and (a.deleted_at is null) and (a.`type` <> 'application'))  (cost=0.251 rows=0.05) (actual time=0.0016..0.00161 rows=0.304 loops=25098)
                                                            -> Single-row index lookup on a using PRIMARY (id = c.actor_id)  (cost=0.251 rows=1) (actual time=0.00149..0.0015 rows=1 loops=25098)
                                                    -> Single-row index lookup on <subquery9> using <auto_distinct_key> (domain = a.domain)  (cost=42..42 rows=1) (actual time=163e-6..163e-6 rows=0 loops=7641)
                                                        -> Materialize with deduplication  (cost=1.33..1.33 rows=1) (actual time=0.00154..0.00154 rows=0 loops=1)
                                                            -> Filter: (b.domain is not null)  (cost=1.1 rows=1) (actual time=0.00117..0.00117 rows=0 loops=1)
                                                                -> Covering index scan on b using domain_blocks_domain_unique  (cost=1.1 rows=1) (actual time=0.00108..0.00108 rows=0 loops=1)
                                                -> Filter: ((p.`status` = 'published') and (p.visibility = 'public') and (p.conversation_id is null) and ((p.community_id is null) or exists(select #7)) and (p.published_at >= TIMESTAMP'2024-10-22 15:00:00') and (p.published_at < TIMESTAMP'2026-10-22 15:00:00'))  (cost=0.25 rows=0.05) (actual time=0.00162..0.00166 rows=0.991 loops=7641)
                                                    -> Single-row index lookup on p using PRIMARY (id = c.post_id)  (cost=0.25 rows=1) (actual time=0.00141..0.00142 rows=1 loops=7641)
                                                    -> Select #7 (subquery in condition; dependent)
                                                        -> Limit: 1 row(s)  (cost=0.35 rows=1) (never executed)
                                                            -> Filter: (c.is_private = 0)  (cost=0.35 rows=1) (never executed)
                                                                -> Single-row index lookup on c using PRIMARY (id = p.community_id)  (cost=0.35 rows=1) (never executed)
                                            -> Filter: ((a.indexable = 1) and (a.discoverable = 1) and (a.remote_suspended = 0) and (a.`status` = 'active') and (a.deleted_at is null) and (a.`type` <> 'application'))  (cost=0.253 rows=0.05) (actual time=0.00118..0.00121 rows=0.637 loops=7575)
                                                -> Single-row index lookup on a using PRIMARY (id = p.actor_id)  (cost=0.253 rows=1) (actual time=0.00106..0.00107 rows=1 loops=7575)
                                        -> Single-row covering index lookup on b using domain_blocks_domain_unique (domain = a.domain)  (cost=1.69 rows=1) (actual time=215e-6..215e-6 rows=0 loops=4825)
                                    -> Nested loop antijoin  (cost=1.84 rows=1) (actual time=0.00231..0.00238 rows=0.218 loops=4825)
                                        -> Nested loop antijoin  (cost=1.6 rows=1) (actual time=0.00221..0.00228 rows=0.218 loops=4825)
                                            -> Nested loop inner join  (cost=1.35 rows=1) (actual time=0.0021..0.00217 rows=0.218 loops=4825)
                                                -> Nested loop inner join  (cost=1.22 rows=1) (actual time=0.00181..0.00186 rows=0.218 loops=4825)
                                                    -> Nested loop inner join  (cost=1.08 rows=1) (actual time=0.00147..0.0015 rows=0.218 loops=4825)
                                                        -> Filter: ((c.post_id = c.post_id) and (c.`status` = 'published'))  (cost=0.943 rows=1) (actual time=783e-6..800e-6 rows=0.422 loops=4825)
                                                            -> Single-row index lookup on c using PRIMARY (id = c.parent_comment_id)  (cost=0.943 rows=1) (actual time=643e-6..649e-6 rows=0.424 loops=4825)
                                                        -> Filter: ((a.indexable = 1) and (a.discoverable = 1) and (a.remote_suspended = 0) and (a.`status` = 'active') and (a.deleted_at is null) and (a.`type` <> 'application'))  (cost=0.944 rows=1) (actual time=0.00145..0.00147 rows=0.517 loops=2034)
                                                            -> Single-row index lookup on a using PRIMARY (id = c.actor_id)  (cost=0.944 rows=1) (actual time=0.0013..0.00131 rows=1 loops=2034)
                                                    -> Filter: ((p.`status` = 'published') and (p.visibility = 'public') and (p.conversation_id is null) and ((p.community_id is null) or exists(select #14)) and (p.published_at >= '2024-10-22 15:00:00') and (p.published_at < '2026-10-22 15:00:00'))  (cost=0.943 rows=1) (actual time=0.0013..0.00134 rows=1 loops=1051)
                                                        -> Single-row index lookup on p using PRIMARY (id = c.post_id)  (cost=0.943 rows=1) (actual time=0.00105..0.00106 rows=1 loops=1051)
                                                        -> Select #14 (subquery in condition; dependent)
                                                            -> Limit: 1 row(s)  (cost=0.35 rows=1) (never executed)
                                                                -> Filter: (c.is_private = 0)  (cost=0.35 rows=1) (never executed)
                                                                    -> Single-row index lookup on c using PRIMARY (id = p.community_id)  (cost=0.35 rows=1) (never executed)
                                                -> Filter: ((a.indexable = 1) and (a.discoverable = 1) and (a.remote_suspended = 0) and (a.`status` = 'active') and (a.deleted_at is null) and (a.`type` <> 'application'))  (cost=0.944 rows=1) (actual time=0.00106..0.0011 rows=1 loops=1051)
                                                    -> Single-row index lookup on a using PRIMARY (id = p.actor_id)  (cost=0.944 rows=1) (actual time=893e-6..907e-6 rows=1 loops=1051)
                                            -> Single-row covering index lookup on b using domain_blocks_domain_unique (domain = a.domain)  (cost=1.69 rows=1) (actual time=218e-6..218e-6 rows=0 loops=1051)
                                        -> Single-row covering index lookup on b using domain_blocks_domain_unique (domain = a.domain)  (cost=1.69 rows=1) (actual time=180e-6..180e-6 rows=0 loops=1051)
                            -> Nested loop antijoin  (cost=1.19 rows=1) (actual time=573e-6..591e-6 rows=0.274 loops=3832)
                                -> Filter: ((a.indexable = 1) and (a.discoverable = 1) and (a.remote_suspended = 0) and (a.`status` = 'active') and (a.deleted_at is null) and (a.`type` <> 'application'))  (cost=0.944 rows=1) (actual time=458e-6..469e-6 rows=0.274 loops=3832)
                                    -> Single-row index lookup on a using PRIMARY (id = c.actor_id)  (cost=0.944 rows=1) (actual time=356e-6..360e-6 rows=0.274 loops=3832)
                                -> Single-row covering index lookup on b using domain_blocks_domain_unique (domain = a.domain)  (cost=1.69 rows=1) (actual time=196e-6..196e-6 rows=0 loops=1051)
```

## Q07 — Quota post con almeno una risposta di altro dominio

Radice ms: `[180, 173, 168]`. Wall ms: `[180.54, 173.45, 168.29]`. Righe risultato: `1`.

```text
-> Aggregate: count(0), sum(exists(select #2))  (cost=1208 rows=1) (actual time=168..168 rows=1 loops=1)
    -> Nested loop antijoin  (cost=1205 rows=12.1) (actual time=0.0409..106 rows=20084 loops=1)
        -> Nested loop inner join  (cost=1202 rows=12.1) (actual time=0.0384..102 rows=20084 loops=1)
            -> Filter: ((p.conversation_id is null) and ((p.community_id is null) or exists(select #8)))  (cost=1117 rows=243) (actual time=0.033..79.5 rows=48751 loops=1)
                -> Index lookup on p using posts_popular_remote_actor_index (status = 'published', visibility = 'public'), with index condition: ((p.published_at >= TIMESTAMP'2024-10-22 15:00:00') and (p.published_at < TIMESTAMP'2026-10-22 15:00:00'))  (cost=1117 rows=21855) (actual time=0.0328..77.8 rows=48751 loops=1)
                -> Select #8 (subquery in condition; dependent)
                    -> Limit: 1 row(s)  (cost=0.35 rows=1) (actual time=0.00287..0.00287 rows=1 loops=1)
                        -> Filter: (c.is_private = 0)  (cost=0.35 rows=1) (actual time=0.00283..0.00283 rows=1 loops=1)
                            -> Single-row index lookup on c using PRIMARY (id = p.community_id)  (cost=0.35 rows=1) (actual time=0.00267..0.00267 rows=1 loops=1)
            -> Filter: ((a.indexable = 1) and (a.discoverable = 1) and (a.remote_suspended = 0) and (a.`status` = 'active') and (a.deleted_at is null) and (a.`type` <> 'application'))  (cost=0.252 rows=0.05) (actual time=399e-6..416e-6 rows=0.412 loops=48751)
                -> Single-row index lookup on a using PRIMARY (id = p.actor_id)  (cost=0.252 rows=1) (actual time=292e-6..306e-6 rows=1 loops=48751)
        -> Single-row index lookup on <subquery7> using <auto_distinct_key> (domain = a.domain)  (cost=2.64..2.64 rows=1) (actual time=143e-6..143e-6 rows=0 loops=20084)
            -> Materialize with deduplication  (cost=1.33..1.33 rows=1) (actual time=0.00179..0.00179 rows=0 loops=1)
                -> Filter: (b.domain is not null)  (cost=1.1 rows=1) (actual time=0.00137..0.00137 rows=0 loops=1)
                    -> Covering index scan on b using domain_blocks_domain_unique  (cost=1.1 rows=1) (actual time=0.00137..0.00137 rows=0 loops=1)
-> Select #2 (subquery in projection; dependent)
    -> Limit: 1 row(s)  (cost=0.941 rows=0.0234) (actual time=0.0029..0.0029 rows=0.0526 loops=20084)
        -> Nested loop antijoin  (cost=0.941 rows=0.0234) (actual time=0.00285..0.00285 rows=0.0526 loops=20084)
            -> Nested loop inner join  (cost=0.915 rows=0.0234) (actual time=0.00277..0.00277 rows=0.0526 loops=20084)
                -> Filter: (c.`status` = 'published')  (cost=0.75 rows=0.469) (actual time=0.00176..0.00199 rows=0.383 loops=20084)
                    -> Index lookup on c using comments_post_id_created_at_index (post_id = p.id), with index condition: ((c.post_id = p.id) and (c.created_at < TIMESTAMP'2026-10-22 15:00:00'))  (cost=0.75 rows=2.81) (actual time=0.0017..0.00191 rows=0.386 loops=20084)
                -> Filter: ((a.indexable = 1) and (a.discoverable = 1) and (a.remote_suspended = 0) and (a.domain <> a.domain) and (a.`status` = 'active') and (a.deleted_at is null) and (a.`type` <> 'application'))  (cost=0.262 rows=0.05) (actual time=0.00186..0.00186 rows=0.137 loops=7692)
                    -> Single-row index lookup on a using PRIMARY (id = c.actor_id)  (cost=0.262 rows=1) (actual time=0.00172..0.00174 rows=1 loops=7692)
            -> Single-row covering index lookup on b using domain_blocks_domain_unique (domain = a.domain)  (cost=5.27 rows=1) (actual time=305e-6..305e-6 rows=0 loops=1056)
```

## Q08 — Geografia: soli post

Radice ms: `[2.33, 0.133, 0.132]`. Wall ms: `[3.01, 0.44, 0.39]`. Righe risultato: `1`.

```text
-> Sort: total DESC, locations.country  (actual time=0.132..0.132 rows=1 loops=1)
    -> Table scan on <temporary>  (actual time=0.131..0.131 rows=1 loops=1)
        -> Aggregate using temporary table  (actual time=0.13..0.13 rows=1 loops=1)
            -> Nested loop antijoin  (cost=22.8 rows=0.12) (actual time=0.00771..0.127 rows=7 loops=1)
                -> Nested loop inner join  (cost=22.7 rows=0.12) (actual time=0.00708..0.124 rows=7 loops=1)
                    -> Nested loop inner join  (cost=21.9 rows=2.4) (actual time=0.0045..0.0996 rows=52 loops=1)
                        -> Table scan on l  (cost=5.05 rows=48) (actual time=0.0015..0.00709 rows=52 loops=1)
                        -> Filter: ((p.`status` = 'published') and (p.visibility = 'public') and (p.conversation_id is null) and ((p.community_id is null) or exists(select #6)) and (p.published_at >= TIMESTAMP'2024-10-22 15:00:00') and (p.published_at < TIMESTAMP'2026-10-22 15:00:00'))  (cost=0.25 rows=0.05) (actual time=0.00167..0.00171 rows=1 loops=52)
                            -> Single-row index lookup on p using PRIMARY (id = l.post_id)  (cost=0.25 rows=1) (actual time=0.00149..0.0015 rows=1 loops=52)
                            -> Select #6 (subquery in condition; dependent)
                                -> Limit: 1 row(s)  (cost=0.35 rows=1) (never executed)
                                    -> Filter: (c.is_private = 0)  (cost=0.35 rows=1) (never executed)
                                        -> Single-row index lookup on c using PRIMARY (id = p.community_id)  (cost=0.35 rows=1) (never executed)
                    -> Filter: ((a.indexable = 1) and (a.discoverable = 1) and (a.remote_suspended = 0) and (a.`status` = 'active') and (a.deleted_at is null) and (a.`type` <> 'application'))  (cost=0.254 rows=0.05) (actual time=426e-6..431e-6 rows=0.135 loops=52)
                        -> Single-row index lookup on a using PRIMARY (id = p.actor_id)  (cost=0.254 rows=1) (actual time=327e-6..337e-6 rows=1 loops=52)
                -> Single-row covering index lookup on b using domain_blocks_domain_unique (domain = a.domain)  (cost=1.83 rows=1) (actual time=250e-6..250e-6 rows=0 loops=7)
```

## Q09 — Geografia: soli eventi

Radice ms: `[1.02, 0.34, 0.34]`. Wall ms: `[1.48, 0.72, 0.56]`. Righe risultato: `7`.

```text
-> Sort: total DESC, locations.country  (actual time=0.34..0.34 rows=7 loops=1)
    -> Table scan on <temporary>  (actual time=0.338..0.338 rows=7 loops=1)
        -> Aggregate using temporary table  (actual time=0.338..0.338 rows=7 loops=1)
            -> Nested loop antijoin  (cost=20.6 rows=0.0811) (actual time=0.00842..0.314 rows=66 loops=1)
                -> Nested loop inner join  (cost=20.5 rows=0.0811) (actual time=0.00783..0.301 rows=66 loops=1)
                    -> Nested loop inner join  (cost=20 rows=1.62) (actual time=0.00521..0.225 rows=135 loops=1)
                        -> Filter: ((e.visibility = 'public') and (e.deleted_at is null) and (e.`status` in ('scheduled','tentative','postponed')) and (e.published_at >= TIMESTAMP'2024-10-22 15:00:00') and (e.published_at < TIMESTAMP'2026-10-22 15:00:00') and (e.actor_id is not null))  (cost=19.4 rows=1.62) (actual time=0.0035..0.0642 rows=145 loops=1)
                            -> Table scan on e  (cost=19.4 rows=149) (actual time=0.00308..0.0357 rows=151 loops=1)
                        -> Single-row index lookup on l using PRIMARY (event_id = e.id)  (cost=0.312 rows=1) (actual time=0.00102..0.00103 rows=0.931 loops=145)
                    -> Filter: ((a.indexable = 1) and (a.discoverable = 1) and (a.remote_suspended = 0) and (a.`status` = 'active') and (a.deleted_at is null) and (a.`type` <> 'application'))  (cost=0.255 rows=0.05) (actual time=483e-6..504e-6 rows=0.489 loops=135)
                        -> Single-row index lookup on a using PRIMARY (id = e.actor_id)  (cost=0.255 rows=1) (actual time=361e-6..379e-6 rows=1 loops=135)
                -> Single-row covering index lookup on b using domain_blocks_domain_unique (domain = a.domain)  (cost=2.23 rows=1) (actual time=129e-6..129e-6 rows=0 loops=66)
```

## Q10 — Geografia: post ed eventi insieme e distinti

Radice ms: `[0.537, 0.537, 0.528]`. Wall ms: `[1.24, 1.03, 0.98]`. Righe risultato: `7`.

```text
-> Sort: total DESC, locations.country  (actual time=0.528..0.528 rows=7 loops=1)
    -> Table scan on <temporary>  (actual time=0.526..0.526 rows=7 loops=1)
        -> Aggregate using temporary table  (actual time=0.525..0.525 rows=7 loops=1)
            -> Table scan on locations  (cost=46..46 rows=0.201) (actual time=0.5..0.504 rows=73 loops=1)
                -> Union all materialize  (cost=43.5..43.5 rows=0.201) (actual time=0.5..0.5 rows=73 loops=1)
                    -> Nested loop antijoin  (cost=22.8 rows=0.12) (actual time=0.0103..0.156 rows=7 loops=1)
                        -> Nested loop inner join  (cost=22.7 rows=0.12) (actual time=0.00958..0.153 rows=7 loops=1)
                            -> Nested loop inner join  (cost=21.9 rows=2.4) (actual time=0.00579..0.122 rows=52 loops=1)
                                -> Table scan on l  (cost=5.05 rows=48) (actual time=0.00179..0.00837 rows=52 loops=1)
                                -> Filter: ((p.`status` = 'published') and (p.visibility = 'public') and (p.conversation_id is null) and ((p.community_id is null) or exists(select #6)) and (p.published_at >= TIMESTAMP'2024-10-22 15:00:00') and (p.published_at < TIMESTAMP'2026-10-22 15:00:00'))  (cost=0.25 rows=0.05) (actual time=0.00204..0.00209 rows=1 loops=52)
                                    -> Single-row index lookup on p using PRIMARY (id = l.post_id)  (cost=0.25 rows=1) (actual time=0.00183..0.00184 rows=1 loops=52)
                                    -> Select #6 (subquery in condition; dependent)
                                        -> Limit: 1 row(s)  (cost=0.35 rows=1) (never executed)
                                            -> Filter: (c.is_private = 0)  (cost=0.35 rows=1) (never executed)
                                                -> Single-row index lookup on c using PRIMARY (id = p.community_id)  (cost=0.35 rows=1) (never executed)
                            -> Filter: ((a.indexable = 1) and (a.discoverable = 1) and (a.remote_suspended = 0) and (a.`status` = 'active') and (a.deleted_at is null) and (a.`type` <> 'application'))  (cost=0.254 rows=0.05) (actual time=544e-6..552e-6 rows=0.135 loops=52)
                                -> Single-row index lookup on a using PRIMARY (id = p.actor_id)  (cost=0.254 rows=1) (actual time=421e-6..435e-6 rows=1 loops=52)
                        -> Single-row covering index lookup on b using domain_blocks_domain_unique (domain = a.domain)  (cost=1.83 rows=1) (actual time=304e-6..304e-6 rows=0 loops=7)
                    -> Nested loop antijoin  (cost=20.6 rows=0.0811) (actual time=0.00804..0.331 rows=66 loops=1)
                        -> Nested loop inner join  (cost=20.5 rows=0.0811) (actual time=0.00754..0.317 rows=66 loops=1)
                            -> Nested loop inner join  (cost=20 rows=1.62) (actual time=0.00492..0.238 rows=135 loops=1)
                                -> Filter: ((e.visibility = 'public') and (e.deleted_at is null) and (e.`status` in ('scheduled','tentative','postponed')) and (e.published_at >= TIMESTAMP'2024-10-22 15:00:00') and (e.published_at < TIMESTAMP'2026-10-22 15:00:00') and (e.actor_id is not null))  (cost=19.4 rows=1.62) (actual time=0.00337..0.0668 rows=145 loops=1)
                                    -> Table scan on e  (cost=19.4 rows=149) (actual time=0.00292..0.0371 rows=151 loops=1)
                                -> Single-row index lookup on l using PRIMARY (event_id = e.id)  (cost=0.312 rows=1) (actual time=0.00109..0.0011 rows=0.931 loops=145)
                            -> Filter: ((a.indexable = 1) and (a.discoverable = 1) and (a.remote_suspended = 0) and (a.`status` = 'active') and (a.deleted_at is null) and (a.`type` <> 'application'))  (cost=0.255 rows=0.05) (actual time=506e-6..529e-6 rows=0.489 loops=135)
                                -> Single-row index lookup on a using PRIMARY (id = e.actor_id)  (cost=0.255 rows=1) (actual time=385e-6..400e-6 rows=1 loops=135)
                        -> Single-row covering index lookup on b using domain_blocks_domain_unique (domain = a.domain)  (cost=2.23 rows=1) (actual time=141e-6..141e-6 rows=0 loops=66)
```

## Q11 — Copertura dei geotag: denominatori per post ed eventi

Radice ms: `[121, 114, 115]`. Wall ms: `[121.59, 115.05, 116.19]`. Righe risultato: `2`.

```text
-> Append  (cost=1232 rows=2) (actual time=115..115 rows=2 loops=1)
    -> Stream results  (cost=1212 rows=1) (actual time=115..115 rows=1 loops=1)
        -> Aggregate: count(0), count(l.post_id)  (cost=1212 rows=1) (actual time=115..115 rows=1 loops=1)
            -> Nested loop left join  (cost=1209 rows=12.1) (actual time=0.043..115 rows=20084 loops=1)
                -> Nested loop antijoin  (cost=1205 rows=12.1) (actual time=0.042..105 rows=20084 loops=1)
                    -> Nested loop inner join  (cost=1202 rows=12.1) (actual time=0.0398..102 rows=20084 loops=1)
                        -> Filter: ((p.conversation_id is null) and ((p.community_id is null) or exists(select #5)))  (cost=1117 rows=243) (actual time=0.0337..78.4 rows=48751 loops=1)
                            -> Index lookup on p using posts_popular_remote_actor_index (status = 'published', visibility = 'public'), with index condition: ((p.published_at >= TIMESTAMP'2024-10-22 15:00:00') and (p.published_at < TIMESTAMP'2026-10-22 15:00:00'))  (cost=1117 rows=21855) (actual time=0.0335..76.8 rows=48751 loops=1)
                            -> Select #5 (subquery in condition; dependent)
                                -> Limit: 1 row(s)  (cost=0.35 rows=1) (actual time=0.00225..0.00225 rows=1 loops=1)
                                    -> Filter: (c.is_private = 0)  (cost=0.35 rows=1) (actual time=0.00221..0.00221 rows=1 loops=1)
                                        -> Single-row index lookup on c using PRIMARY (id = p.community_id)  (cost=0.35 rows=1) (actual time=0.00208..0.00208 rows=1 loops=1)
                        -> Filter: ((a.indexable = 1) and (a.discoverable = 1) and (a.remote_suspended = 0) and (a.`status` = 'active') and (a.deleted_at is null) and (a.`type` <> 'application'))  (cost=0.252 rows=0.05) (actual time=405e-6..422e-6 rows=0.412 loops=48751)
                            -> Single-row index lookup on a using PRIMARY (id = p.actor_id)  (cost=0.252 rows=1) (actual time=301e-6..316e-6 rows=1 loops=48751)
                    -> Single-row index lookup on <subquery4> using <auto_distinct_key> (domain = a.domain)  (cost=1.44..1.44 rows=1) (actual time=141e-6..141e-6 rows=0 loops=20084)
                        -> Materialize with deduplication  (cost=1.33..1.33 rows=1) (actual time=0.00146..0.00146 rows=0 loops=1)
                            -> Filter: (b.domain is not null)  (cost=1.1 rows=1) (actual time=0.00108..0.00108 rows=0 loops=1)
                                -> Covering index scan on b using domain_blocks_domain_unique  (cost=1.1 rows=1) (actual time=0.00104..0.00104 rows=0 loops=1)
                -> Single-row covering index lookup on l using PRIMARY (post_id = p.id)  (cost=0.258 rows=1) (actual time=409e-6..409e-6 rows=349e-6 loops=20084)
    -> Stream results  (cost=20.1 rows=1) (actual time=0.256..0.256 rows=1 loops=1)
        -> Aggregate: count(0), count(l.event_id)  (cost=20.1 rows=1) (actual time=0.254..0.254 rows=1 loops=1)
            -> Nested loop left join  (cost=20.1 rows=0.0811) (actual time=0.0199..0.251 rows=76 loops=1)
                -> Nested loop antijoin  (cost=20.1 rows=0.0811) (actual time=0.0164..0.172 rows=76 loops=1)
                    -> Nested loop inner join  (cost=20 rows=0.0811) (actual time=0.0146..0.157 rows=76 loops=1)
                        -> Filter: ((e.visibility = 'public') and (e.deleted_at is null) and (e.`status` in ('scheduled','tentative','postponed')) and (e.published_at >= TIMESTAMP'2024-10-22 15:00:00') and (e.published_at < TIMESTAMP'2026-10-22 15:00:00') and (e.actor_id is not null))  (cost=19.4 rows=1.62) (actual time=0.00929..0.076 rows=145 loops=1)
                            -> Table scan on e  (cost=19.4 rows=149) (actual time=0.008..0.049 rows=151 loops=1)
                        -> Filter: ((a.indexable = 1) and (a.discoverable = 1) and (a.remote_suspended = 0) and (a.`status` = 'active') and (a.deleted_at is null) and (a.`type` <> 'application'))  (cost=0.255 rows=0.05) (actual time=481e-6..502e-6 rows=0.524 loops=145)
                            -> Single-row index lookup on a using PRIMARY (id = e.actor_id)  (cost=0.255 rows=1) (actual time=374e-6..386e-6 rows=1 loops=145)
                    -> Single-row covering index lookup on b using domain_blocks_domain_unique (domain = a.domain)  (cost=2.23 rows=1) (actual time=133e-6..133e-6 rows=0 loops=76)
                -> Single-row covering index lookup on l using PRIMARY (event_id = e.id)  (cost=1.48 rows=1) (actual time=959e-6..969e-6 rows=0.868 loops=76)
```

## Q12 — Allegati immagine/video e post distinti, per settimana

Radice ms: `[363, 183, 177]`. Wall ms: `[363.83, 183.7, 178.3]`. Righe risultato: `65`.

```text
-> Group aggregate: count(0), count(distinct posts.id)  (actual time=172..177 rows=65 loops=1)
    -> Sort: week_start, p.is_local, kind  (actual time=172..172 rows=11870 loops=1)
        -> Stream results  (cost=1217 rows=2.79) (actual time=0.0601..166 rows=11870 loops=1)
            -> Nested loop inner join  (cost=1217 rows=2.79) (actual time=0.0588..164 rows=11870 loops=1)
                -> Nested loop inner join  (cost=1209 rows=13.3) (actual time=0.0559..141 rows=11923 loops=1)
                    -> Nested loop antijoin  (cost=1205 rows=12.1) (actual time=0.0483..110 rows=20084 loops=1)
                        -> Nested loop inner join  (cost=1202 rows=12.1) (actual time=0.0457..106 rows=20084 loops=1)
                            -> Filter: ((p.conversation_id is null) and ((p.community_id is null) or exists(select #5)))  (cost=1117 rows=243) (actual time=0.0384..82.6 rows=48751 loops=1)
                                -> Index lookup on p using posts_popular_remote_actor_index (status = 'published', visibility = 'public'), with index condition: ((p.published_at >= TIMESTAMP'2024-10-22 15:00:00') and (p.published_at < TIMESTAMP'2026-10-22 15:00:00'))  (cost=1117 rows=21855) (actual time=0.0383..80.8 rows=48751 loops=1)
                                -> Select #5 (subquery in condition; dependent)
                                    -> Limit: 1 row(s)  (cost=0.35 rows=1) (actual time=0.00408..0.00413 rows=1 loops=1)
                                        -> Filter: (c.is_private = 0)  (cost=0.35 rows=1) (actual time=0.004..0.004 rows=1 loops=1)
                                            -> Single-row index lookup on c using PRIMARY (id = p.community_id)  (cost=0.35 rows=1) (actual time=0.004..0.004 rows=1 loops=1)
                            -> Filter: ((a.indexable = 1) and (a.discoverable = 1) and (a.remote_suspended = 0) and (a.`status` = 'active') and (a.deleted_at is null) and (a.`type` <> 'application'))  (cost=0.252 rows=0.05) (actual time=419e-6..436e-6 rows=0.412 loops=48751)
                                -> Single-row index lookup on a using PRIMARY (id = p.actor_id)  (cost=0.252 rows=1) (actual time=311e-6..325e-6 rows=1 loops=48751)
                        -> Single-row index lookup on <subquery4> using <auto_distinct_key> (domain = a.domain)  (cost=1.8..1.8 rows=1) (actual time=145e-6..145e-6 rows=0 loops=20084)
                            -> Materialize with deduplication  (cost=1.33..1.33 rows=1) (actual time=0.002..0.002 rows=0 loops=1)
                                -> Filter: (b.domain is not null)  (cost=1.1 rows=1) (actual time=0.00154..0.00154 rows=0 loops=1)
                                    -> Covering index scan on b using domain_blocks_domain_unique  (cost=1.1 rows=1) (actual time=0.0015..0.0015 rows=0 loops=1)
                    -> Covering index lookup on att using post_attachments_post_id_media_id_unique (post_id = p.id)  (cost=0.26 rows=1.1) (actual time=0.00135..0.00145 rows=0.594 loops=20084)
                -> Filter: ((m.mime_type like 'image/%') or (m.mime_type like 'video/%'))  (cost=0.503 rows=0.21) (actual time=0.00183..0.00187 rows=0.996 loops=11923)
                    -> Single-row index lookup on m using PRIMARY (id = att.media_id)  (cost=0.503 rows=1) (actual time=0.00175..0.00177 rows=1 loops=11923)
```

## Q13 — Ritmo degli ultimi 15 giorni: 360 bucket orari

Radice ms: `[4.89, 4.59, 4.64]`. Wall ms: `[7.4, 5.02, 5.09]`. Righe risultato: `55`.

```text
-> Sort: hour_start, public_posts.is_local  (actual time=4.64..4.64 rows=55 loops=1)
    -> Table scan on <temporary>  (actual time=4.62..4.63 rows=55 loops=1)
        -> Aggregate using temporary table  (actual time=4.62..4.62 rows=55 loops=1)
            -> Nested loop antijoin  (cost=659 rows=6.78) (actual time=0.0377..4.35 rows=819 loops=1)
                -> Nested loop inner join  (cost=658 rows=6.78) (actual time=0.0362..4.18 rows=819 loops=1)
                    -> Filter: ((p.conversation_id is null) and ((p.community_id is null) or exists(select #5)))  (cost=610 rows=136) (actual time=0.033..2.18 rows=1356 loops=1)
                        -> Index range scan on p using posts_visibility_status_published_at_index over (visibility = 'public' AND status = 'published' AND '2026-10-07 15:00:00' <= published_at < '2026-10-22 15:00:00'), with index condition: ((p.published_at >= TIMESTAMP'2026-10-07 15:00:00') and (p.`status` = 'published') and (p.visibility = 'public') and (p.published_at >= TIMESTAMP'2024-10-22 15:00:00') and (p.published_at < TIMESTAMP'2026-10-22 15:00:00'))  (cost=610 rows=1356) (actual time=0.033..2.13 rows=1356 loops=1)
                        -> Select #5 (subquery in condition; dependent)
                            -> Limit: 1 row(s)  (cost=0.35 rows=1) (never executed)
                                -> Filter: (c.is_private = 0)  (cost=0.35 rows=1) (never executed)
                                    -> Single-row index lookup on c using PRIMARY (id = p.community_id)  (cost=0.35 rows=1) (never executed)
                    -> Filter: ((a.indexable = 1) and (a.discoverable = 1) and (a.remote_suspended = 0) and (a.`status` = 'active') and (a.deleted_at is null) and (a.`type` <> 'application'))  (cost=0.252 rows=0.05) (actual time=0.0014..0.00142 rows=0.604 loops=1356)
                        -> Single-row index lookup on a using PRIMARY (id = p.actor_id)  (cost=0.252 rows=1) (actual time=0.00128..0.0013 rows=1 loops=1356)
                -> Single-row index lookup on <subquery4> using <auto_distinct_key> (domain = a.domain)  (cost=2.11..2.11 rows=1) (actual time=148e-6..148e-6 rows=0 loops=819)
                    -> Materialize with deduplication  (cost=1.33..1.33 rows=1) (actual time=959e-6..959e-6 rows=0 loops=1)
                        -> Filter: (b.domain is not null)  (cost=1.1 rows=1) (actual time=667e-6..667e-6 rows=0 loops=1)
                            -> Covering index scan on b using domain_blocks_domain_unique  (cost=1.1 rows=1) (actual time=625e-6..625e-6 rows=0 loops=1)
```

## Q14 — Domini autori dei post per settimana

Radice ms: `[119, 114, 114]`. Wall ms: `[119.74, 115.21, 114.34]`. Righe risultato: `1288`.

```text
-> Sort: week_start, post_count DESC, public_posts.domain  (actual time=114..114 rows=1288 loops=1)
    -> Table scan on <temporary>  (actual time=110..110 rows=1288 loops=1)
        -> Aggregate using temporary table  (actual time=110..110 rows=1288 loops=1)
            -> Nested loop antijoin  (cost=1205 rows=12.1) (actual time=0.0413..104 rows=20084 loops=1)
                -> Nested loop inner join  (cost=1202 rows=12.1) (actual time=0.0387..99.6 rows=20084 loops=1)
                    -> Filter: ((p.conversation_id is null) and ((p.community_id is null) or exists(select #5)))  (cost=1117 rows=243) (actual time=0.0322..76.6 rows=48751 loops=1)
                        -> Index lookup on p using posts_popular_remote_actor_index (status = 'published', visibility = 'public'), with index condition: ((p.published_at >= TIMESTAMP'2024-10-22 15:00:00') and (p.published_at < TIMESTAMP'2026-10-22 15:00:00'))  (cost=1117 rows=21855) (actual time=0.032..75 rows=48751 loops=1)
                        -> Select #5 (subquery in condition; dependent)
                            -> Limit: 1 row(s)  (cost=0.35 rows=1) (actual time=0.00229..0.00229 rows=1 loops=1)
                                -> Filter: (c.is_private = 0)  (cost=0.35 rows=1) (actual time=0.00225..0.00225 rows=1 loops=1)
                                    -> Single-row index lookup on c using PRIMARY (id = p.community_id)  (cost=0.35 rows=1) (actual time=0.00221..0.00221 rows=1 loops=1)
                    -> Filter: ((a.indexable = 1) and (a.discoverable = 1) and (a.remote_suspended = 0) and (a.`status` = 'active') and (a.deleted_at is null) and (a.`type` <> 'application'))  (cost=0.252 rows=0.05) (actual time=401e-6..418e-6 rows=0.412 loops=48751)
                        -> Single-row index lookup on a using PRIMARY (id = p.actor_id)  (cost=0.252 rows=1) (actual time=297e-6..311e-6 rows=1 loops=48751)
                -> Single-row index lookup on <subquery4> using <auto_distinct_key> (domain = a.domain)  (cost=2.64..2.64 rows=1) (actual time=141e-6..141e-6 rows=0 loops=20084)
                    -> Materialize with deduplication  (cost=1.33..1.33 rows=1) (actual time=0.00183..0.00183 rows=0 loops=1)
                        -> Filter: (b.domain is not null)  (cost=1.1 rows=1) (actual time=0.00142..0.00142 rows=0 loops=1)
                            -> Covering index scan on b using domain_blocks_domain_unique  (cost=1.1 rows=1) (actual time=0.00137..0.00137 rows=0 loops=1)
```

## Q15 — Autori attivi per settimana

Radice ms: `[125, 125, 122]`. Wall ms: `[126.44, 125.29, 122.31]`. Righe risultato: `68`.

```text
-> Group aggregate: count(distinct posts.actor_id)  (actual time=112..122 rows=68 loops=1)
    -> Sort: week_start, public_posts.is_local  (actual time=112..113 rows=20084 loops=1)
        -> Stream results  (cost=1205 rows=12.1) (actual time=0.0439..107 rows=20084 loops=1)
            -> Nested loop antijoin  (cost=1205 rows=12.1) (actual time=0.0427..105 rows=20084 loops=1)
                -> Nested loop inner join  (cost=1202 rows=12.1) (actual time=0.0407..101 rows=20084 loops=1)
                    -> Filter: ((p.conversation_id is null) and ((p.community_id is null) or exists(select #5)))  (cost=1117 rows=243) (actual time=0.0345..78 rows=48751 loops=1)
                        -> Index lookup on p using posts_popular_remote_actor_index (status = 'published', visibility = 'public'), with index condition: ((p.published_at >= TIMESTAMP'2024-10-22 15:00:00') and (p.published_at < TIMESTAMP'2026-10-22 15:00:00'))  (cost=1117 rows=21855) (actual time=0.0342..76.3 rows=48751 loops=1)
                        -> Select #5 (subquery in condition; dependent)
                            -> Limit: 1 row(s)  (cost=0.35 rows=1) (actual time=0.00538..0.00538 rows=1 loops=1)
                                -> Filter: (c.is_private = 0)  (cost=0.35 rows=1) (actual time=0.00529..0.00529 rows=1 loops=1)
                                    -> Single-row index lookup on c using PRIMARY (id = p.community_id)  (cost=0.35 rows=1) (actual time=0.00529..0.00529 rows=1 loops=1)
                    -> Filter: ((a.indexable = 1) and (a.discoverable = 1) and (a.remote_suspended = 0) and (a.`status` = 'active') and (a.deleted_at is null) and (a.`type` <> 'application'))  (cost=0.252 rows=0.05) (actual time=404e-6..421e-6 rows=0.412 loops=48751)
                        -> Single-row index lookup on a using PRIMARY (id = p.actor_id)  (cost=0.252 rows=1) (actual time=301e-6..315e-6 rows=1 loops=48751)
                -> Single-row index lookup on <subquery4> using <auto_distinct_key> (domain = a.domain)  (cost=2.64..2.64 rows=1) (actual time=142e-6..142e-6 rows=0 loops=20084)
                    -> Materialize with deduplication  (cost=1.33..1.33 rows=1) (actual time=0.00158..0.00158 rows=0 loops=1)
                        -> Filter: (b.domain is not null)  (cost=1.1 rows=1) (actual time=0.00125..0.00125 rows=0 loops=1)
                            -> Covering index scan on b using domain_blocks_domain_unique  (cost=1.1 rows=1) (actual time=0.00125..0.00125 rows=0 loops=1)
```

## Q16 — Diffusione hashtag tra autori e domini

Radice ms: `[302, 343, 328]`. Wall ms: `[304.15, 346.65, 331.21]`. Righe risultato: `10`.

```text
-> Sort: post_count DESC, ph.hashtag_id  (actual time=328..328 rows=10 loops=1)
    -> Stream results  (actual time=325..328 rows=10 loops=1)
        -> Group aggregate: count(0), count(distinct posts.actor_id), count(distinct actors.domain)  (actual time=325..328 rows=10 loops=1)
            -> Sort: ph.hashtag_id  (actual time=323..323 rows=9104 loops=1)
                -> Stream results  (cost=1216 rows=15.1) (actual time=3.37..311 rows=9104 loops=1)
                    -> Nested loop inner join  (cost=1216 rows=15.1) (actual time=3.37..310 rows=9104 loops=1)
                        -> Nested loop antijoin  (cost=1205 rows=12.1) (actual time=0.446..249 rows=20084 loops=1)
                            -> Nested loop inner join  (cost=1202 rows=12.1) (actual time=0.443..245 rows=20084 loops=1)
                                -> Filter: ((p.conversation_id is null) and ((p.community_id is null) or exists(select #5)))  (cost=1117 rows=243) (actual time=0.435..222 rows=48751 loops=1)
                                    -> Index lookup on p using posts_popular_remote_actor_index (status = 'published', visibility = 'public'), with index condition: ((p.published_at >= TIMESTAMP'2024-10-22 15:00:00') and (p.published_at < TIMESTAMP'2026-10-22 15:00:00'))  (cost=1117 rows=21855) (actual time=0.435..220 rows=48751 loops=1)
                                    -> Select #5 (subquery in condition; dependent)
                                        -> Limit: 1 row(s)  (cost=0.35 rows=1) (actual time=0.00688..0.00692 rows=1 loops=1)
                                            -> Filter: (c.is_private = 0)  (cost=0.35 rows=1) (actual time=0.00675..0.00675 rows=1 loops=1)
                                                -> Single-row index lookup on c using PRIMARY (id = p.community_id)  (cost=0.35 rows=1) (actual time=0.00654..0.00654 rows=1 loops=1)
                                -> Filter: ((a.indexable = 1) and (a.discoverable = 1) and (a.remote_suspended = 0) and (a.`status` = 'active') and (a.deleted_at is null) and (a.`type` <> 'application'))  (cost=0.252 rows=0.05) (actual time=408e-6..424e-6 rows=0.412 loops=48751)
                                    -> Single-row index lookup on a using PRIMARY (id = p.actor_id)  (cost=0.252 rows=1) (actual time=303e-6..317e-6 rows=1 loops=48751)
                            -> Single-row index lookup on <subquery4> using <auto_distinct_key> (domain = a.domain)  (cost=1.42..1.42 rows=1) (actual time=142e-6..142e-6 rows=0 loops=20084)
                                -> Materialize with deduplication  (cost=1.33..1.33 rows=1) (actual time=0.00204..0.00204 rows=0 loops=1)
                                    -> Filter: (b.domain is not null)  (cost=1.1 rows=1) (actual time=0.00167..0.00167 rows=0 loops=1)
                                        -> Covering index scan on b using domain_blocks_domain_unique  (cost=1.1 rows=1) (actual time=0.00158..0.00158 rows=0 loops=1)
                        -> Filter: (ph.hashtag_id in ('019fc806-7d4e-715b-8dd2-85520afba1e9','019fc832-28cb-736b-959a-869dc994435a','019fc806-7d50-72ce-9acf-d39093bc7ed8','019fd6cd-3552-70ba-8784-d43ef9f45de6','019ff7b5-9000-7299-97c8-89786db1f3cd','019fd0e4-ac6c-7228-a881-d196daa2002a','019fda3c-1c16-72c4-88f4-63649c606bb1','019fd0e4-ac49-712f-9eb4-42367b63a376','019ff81b-825d-712d-aad9-708c115668d7','019fd1c5-9092-7342-8945-ce11382f7f39'))  (cost=0.338 rows=1.24) (actual time=0.00251..0.00296 rows=0.453 loops=20084)
                            -> Covering index lookup on ph using PRIMARY (post_id = p.id)  (cost=0.338 rows=5.67) (actual time=0.00217..0.00251 rows=3.37 loops=20084)
```

## Q17 — Eventi pubblici per mese di inizio e stato

Radice ms: `[0.213, 0.178, 0.175]`. Wall ms: `[0.85, 0.43, 0.39]`. Righe risultato: `9`.

```text
-> Sort: month_start, public_events.`status`  (actual time=0.175..0.175 rows=9 loops=1)
    -> Table scan on <temporary>  (actual time=0.171..0.171 rows=9 loops=1)
        -> Aggregate using temporary table  (actual time=0.171..0.171 rows=9 loops=1)
            -> Nested loop antijoin  (cost=20.1 rows=0.0811) (actual time=0.00717..0.145 rows=76 loops=1)
                -> Nested loop inner join  (cost=20 rows=0.0811) (actual time=0.0065..0.132 rows=76 loops=1)
                    -> Filter: ((e.visibility = 'public') and (e.deleted_at is null) and (e.`status` in ('scheduled','tentative','postponed')) and (e.published_at >= TIMESTAMP'2024-10-22 15:00:00') and (e.published_at < TIMESTAMP'2026-10-22 15:00:00') and (e.actor_id is not null))  (cost=19.4 rows=1.62) (actual time=0.00375..0.0585 rows=145 loops=1)
                        -> Table scan on e  (cost=19.4 rows=149) (actual time=0.00321..0.0342 rows=151 loops=1)
                    -> Filter: ((a.indexable = 1) and (a.discoverable = 1) and (a.remote_suspended = 0) and (a.`status` = 'active') and (a.deleted_at is null) and (a.`type` <> 'application'))  (cost=0.275 rows=0.05) (actual time=422e-6..443e-6 rows=0.524 loops=145)
                        -> Single-row index lookup on a using PRIMARY (id = e.actor_id)  (cost=0.275 rows=1) (actual time=321e-6..333e-6 rows=1 loops=145)
                -> Single-row covering index lookup on b using domain_blocks_domain_unique (domain = a.domain)  (cost=2.23 rows=1) (actual time=116e-6..116e-6 rows=0 loops=76)
```

## Q18 — Community pubbliche attive per settimana

Radice ms: `[0.0111, 0.00604, 0.00554]`. Wall ms: `[0.58, 0.35, 0.31]`. Righe risultato: `0`.

```text
-> Group aggregate: count(distinct posts.community_id), count(0)  (actual time=0.00554..0.00554 rows=0 loops=1)
    -> Sort: week_start  (actual time=0.00542..0.00542 rows=0 loops=1)
        -> Stream results  (cost=79.9 rows=0.303) (actual time=0.005..0.005 rows=0 loops=1)
            -> Nested loop antijoin  (cost=79.9 rows=0.303) (actual time=0.00483..0.00483 rows=0 loops=1)
                -> Nested loop inner join  (cost=79.5 rows=0.303) (actual time=0.00479..0.00479 rows=0 loops=1)
                    -> Nested loop inner join  (cost=77.3 rows=6.07) (actual time=0.00475..0.00475 rows=0 loops=1)
                        -> Nested loop antijoin  (cost=0.777 rows=0.05) (actual time=0.00467..0.00467 rows=0 loops=1)
                            -> Nested loop inner join  (cost=0.722 rows=0.05) (actual time=0.00462..0.00462 rows=0 loops=1)
                                -> Filter: (c.is_private = 0)  (cost=0.35 rows=1) (actual time=0.00129..0.00158 rows=1 loops=1)
                                    -> Table scan on c  (cost=0.35 rows=1) (actual time=0.00112..0.00133 rows=1 loops=1)
                                -> Filter: ((a.indexable = 1) and (a.discoverable = 1) and (a.remote_suspended = 0) and (a.`status` = 'active') and (a.deleted_at is null) and (a.`type` <> 'application'))  (cost=0.277 rows=0.05) (actual time=0.003..0.003 rows=0 loops=1)
                                    -> Single-row index lookup on a using PRIMARY (id = c.actor_id)  (cost=0.277 rows=1) (actual time=0.00283..0.00287 rows=1 loops=1)
                            -> Single-row covering index lookup on b using domain_blocks_domain_unique (domain = a.domain)  (cost=3 rows=1) (never executed)
                        -> Filter: ((p.visibility = 'public') and ((p.community_id is null) or exists(select #5)))  (cost=1336 rows=121) (never executed)
                            -> Index lookup on p using posts_community_feed_timeline_index (community_id = c.id, status = 'published', conversation_id = NULL), with index condition: ((p.conversation_id is null) and (p.published_at >= TIMESTAMP'2024-10-22 15:00:00') and (p.published_at < TIMESTAMP'2026-10-22 15:00:00'))  (cost=1336 rows=4371) (never executed)
                            -> Select #5 (subquery in condition; dependent)
                                -> Limit: 1 row(s)  (cost=0.35 rows=1) (never executed)
                                    -> Filter: (c.is_private = 0)  (cost=0.35 rows=1) (never executed)
                                        -> Single-row index lookup on c using PRIMARY (id = p.community_id)  (cost=0.35 rows=1) (never executed)
                    -> Filter: ((a.indexable = 1) and (a.discoverable = 1) and (a.remote_suspended = 0) and (a.`status` = 'active') and (a.deleted_at is null) and (a.`type` <> 'application'))  (cost=0.273 rows=0.05) (never executed)
                        -> Single-row index lookup on a using PRIMARY (id = p.actor_id)  (cost=0.273 rows=1) (never executed)
                -> Single-row covering index lookup on b using domain_blocks_domain_unique (domain = a.domain)  (cost=1.33 rows=1) (never executed)
```

## Q19 — Quota di post con sondaggio per settimana

Radice ms: `[133, 119, 122]`. Wall ms: `[134.14, 119.45, 122.34]`. Righe risultato: `68`.

```text
-> Sort: week_start, p.is_local  (actual time=122..122 rows=68 loops=1)
    -> Table scan on <temporary>  (actual time=122..122 rows=68 loops=1)
        -> Aggregate using temporary table  (actual time=122..122 rows=68 loops=1)
            -> Nested loop left join  (cost=1223 rows=12.1) (actual time=0.0487..117 rows=20084 loops=1)
                -> Nested loop antijoin  (cost=1210 rows=12.1) (actual time=0.0477..110 rows=20084 loops=1)
                    -> Nested loop inner join  (cost=1207 rows=12.1) (actual time=0.0442..106 rows=20084 loops=1)
                        -> Filter: ((p.conversation_id is null) and ((p.community_id is null) or exists(select #5)))  (cost=1117 rows=243) (actual time=0.0369..82.9 rows=48751 loops=1)
                            -> Index lookup on p using posts_popular_remote_actor_index (status = 'published', visibility = 'public'), with index condition: ((p.published_at >= TIMESTAMP'2024-10-22 15:00:00') and (p.published_at < TIMESTAMP'2026-10-22 15:00:00'))  (cost=1117 rows=21855) (actual time=0.0366..81.2 rows=48751 loops=1)
                            -> Select #5 (subquery in condition; dependent)
                                -> Limit: 1 row(s)  (cost=0.35 rows=1) (actual time=0.00458..0.00463 rows=1 loops=1)
                                    -> Filter: (c.is_private = 0)  (cost=0.35 rows=1) (actual time=0.00437..0.00437 rows=1 loops=1)
                                        -> Single-row index lookup on c using PRIMARY (id = p.community_id)  (cost=0.35 rows=1) (actual time=0.00412..0.00412 rows=1 loops=1)
                        -> Filter: ((a.indexable = 1) and (a.discoverable = 1) and (a.remote_suspended = 0) and (a.`status` = 'active') and (a.deleted_at is null) and (a.`type` <> 'application'))  (cost=0.272 rows=0.05) (actual time=407e-6..425e-6 rows=0.412 loops=48751)
                            -> Single-row index lookup on a using PRIMARY (id = p.actor_id)  (cost=0.272 rows=1) (actual time=302e-6..316e-6 rows=1 loops=48751)
                    -> Single-row index lookup on <subquery4> using <auto_distinct_key> (domain = a.domain)  (cost=1.44..1.44 rows=1) (actual time=147e-6..147e-6 rows=0 loops=20084)
                        -> Materialize with deduplication  (cost=1.33..1.33 rows=1) (actual time=0.00242..0.00242 rows=0 loops=1)
                            -> Filter: (b.domain is not null)  (cost=1.1 rows=1) (actual time=0.00183..0.00183 rows=0 loops=1)
                                -> Covering index scan on b using domain_blocks_domain_unique  (cost=1.1 rows=1) (actual time=0.00179..0.00179 rows=0 loops=1)
                -> Single-row covering index lookup on pol using polls_post_id_unique (post_id = p.id)  (cost=1.01 rows=1) (actual time=299e-6..299e-6 rows=349e-6 loops=20084)
```

## Q02B — Top hashtag: aggregazione prima del join ai nomi

Radice ms: `[616, 473, 373]`. Wall ms: `[619.35, 475.8, 376.13]`. Righe risultato: `10`.

```text
-> Limit: 10 row(s)  (actual time=373..373 rows=10 loops=1)
    -> Sort: tc.post_count DESC, h.`name`, h.id, limit input to 10 row(s) per chunk  (actual time=373..373 rows=10 loops=1)
        -> Stream results  (cost=19.5 rows=0) (actual time=335..361 rows=14857 loops=1)
            -> Nested loop inner join  (cost=19.5 rows=0) (actual time=335..360 rows=14857 loops=1)
                -> Table scan on tc  (cost=2.5..2.5 rows=0) (actual time=335..335 rows=14857 loops=1)
                    -> Materialize CTE tag_counts  (cost=0..0 rows=0) (actual time=335..335 rows=14857 loops=1)
                        -> Table scan on <temporary>  (actual time=333..334 rows=14857 loops=1)
                            -> Aggregate using temporary table  (actual time=333..333 rows=14857 loops=1)
                                -> Nested loop inner join  (cost=1222 rows=68.9) (actual time=0.296..311 rows=67775 loops=1)
                                    -> Nested loop antijoin  (cost=1211 rows=12.1) (actual time=0.283..252 rows=20084 loops=1)
                                        -> Nested loop inner join  (cost=1209 rows=12.1) (actual time=0.281..247 rows=20084 loops=1)
                                            -> Filter: ((p.conversation_id is null) and ((p.community_id is null) or exists(select #6)))  (cost=1117 rows=243) (actual time=0.271..223 rows=48751 loops=1)
                                                -> Index lookup on p using posts_popular_remote_actor_index (status = 'published', visibility = 'public'), with index condition: ((p.published_at >= TIMESTAMP'2024-10-22 15:00:00') and (p.published_at < TIMESTAMP'2026-10-22 15:00:00'))  (cost=1117 rows=21855) (actual time=0.271..221 rows=48751 loops=1)
                                                -> Select #6 (subquery in condition; dependent)
                                                    -> Limit: 1 row(s)  (cost=0.35 rows=1) (actual time=0.00475..0.00479 rows=1 loops=1)
                                                        -> Filter: (c.is_private = 0)  (cost=0.35 rows=1) (actual time=0.00462..0.00462 rows=1 loops=1)
                                                            -> Single-row index lookup on c using PRIMARY (id = p.community_id)  (cost=0.35 rows=1) (actual time=0.00442..0.00442 rows=1 loops=1)
                                            -> Filter: ((a.indexable = 1) and (a.discoverable = 1) and (a.remote_suspended = 0) and (a.`status` = 'active') and (a.deleted_at is null) and (a.`type` <> 'application'))  (cost=0.277 rows=0.05) (actual time=436e-6..453e-6 rows=0.412 loops=48751)
                                                -> Single-row index lookup on a using PRIMARY (id = p.actor_id)  (cost=0.277 rows=1) (actual time=321e-6..336e-6 rows=1 loops=48751)
                                        -> Single-row index lookup on <subquery5> using <auto_distinct_key> (domain = a.domain)  (cost=1.35..1.35 rows=1) (actual time=154e-6..154e-6 rows=0 loops=20084)
                                            -> Materialize with deduplication  (cost=1.33..1.33 rows=1) (actual time=0.00188..0.00188 rows=0 loops=1)
                                                -> Filter: (b.domain is not null)  (cost=1.1 rows=1) (actual time=0.00154..0.00154 rows=0 loops=1)
                                                    -> Covering index scan on b using domain_blocks_domain_unique  (cost=1.1 rows=1) (actual time=0.0015..0.0015 rows=0 loops=1)
                                    -> Covering index lookup on ph using PRIMARY (post_id = p.id)  (cost=0.375 rows=5.67) (actual time=0.00246..0.00282 rows=3.37 loops=20084)
                -> Filter: (h.`name` <> '')  (cost=0.251 rows=0.5) (actual time=0.00152..0.00156 rows=1 loops=14857)
                    -> Single-row index lookup on h using PRIMARY (id = tc.hashtag_id)  (cost=0.251 rows=1) (actual time=0.00145..0.00147 rows=1 loops=14857)
```

## Q03B — Serie settimanale di 10 hashtag selezionati — indice hashtag forzato (diagnostica)

Radice ms: `[196, 187, 184]`. Wall ms: `[198.25, 188.75, 186.14]`. Righe risultato: `100`.

```text
-> Sort: week_start, ph.hashtag_id  (actual time=184..184 rows=100 loops=1)
    -> Table scan on <temporary>  (actual time=184..184 rows=100 loops=1)
        -> Aggregate using temporary table  (actual time=184..184 rows=100 loops=1)
            -> Filter: (ph.post_id = p.id)  (cost=4126 rows=2.29e-6) (actual time=90..181 rows=9104 loops=1)
                -> Inner hash join (<hash>(ph.post_id)=<hash>(p.id))  (cost=4126 rows=2.29e-6) (actual time=90..180 rows=9104 loops=1)
                    -> Filter: (ph.hashtag_id in ('019fc806-7d4e-715b-8dd2-85520afba1e9','019fc832-28cb-736b-959a-869dc994435a','019fc806-7d50-72ce-9acf-d39093bc7ed8','019fd6cd-3552-70ba-8784-d43ef9f45de6','019ff7b5-9000-7299-97c8-89786db1f3cd','019fd0e4-ac6c-7228-a881-d196daa2002a','019fda3c-1c16-72c4-88f4-63649c606bb1','019fd0e4-ac49-712f-9eb4-42367b63a376','019ff81b-825d-712d-aad9-708c115668d7','019fd1c5-9092-7342-8945-ce11382f7f39'))  (cost=4886 rows=1.24) (actual time=0.121..14.1 rows=24580 loops=1)
                        -> Covering index range scan on ph using post_hashtags_hashtag_id_index over (hashtag_id = '019fc806-7d4e-715b-8dd2-85520afba1e9') OR (hashtag_id = '019fc806-7d50-72ce-9acf-d39093bc7ed8') OR (8 more)  (cost=4886 rows=38478) (actual time=0.12..7.25 rows=24580 loops=1)
                    -> Hash
                        -> Nested loop antijoin  (cost=3847 rows=0.0571) (actual time=0.122..84.4 rows=20084 loops=1)
                            -> Nested loop inner join  (cost=3847 rows=0.0571) (actual time=0.121..81.4 rows=20084 loops=1)
                                -> Filter: ((a.indexable = 1) and (a.discoverable = 1) and (a.remote_suspended = 0) and (a.`status` = 'active') and (a.deleted_at is null) and (a.`type` <> 'application'))  (cost=3845 rows=1) (actual time=0.00729..9.33 rows=6891 loops=1)
                                    -> Table scan on a  (cost=3845 rows=34516) (actual time=0.00687..8.14 rows=33045 loops=1)
                                -> Filter: ((p.conversation_id is null) and ((p.community_id is null) or exists(select #5)))  (cost=1.29 rows=0.0571) (actual time=0.00416..0.0103 rows=2.91 loops=6891)
                                    -> Index lookup on p using posts_popular_remote_actor_index (status = 'published', visibility = 'public', actor_id = a.id), with index condition: ((p.published_at >= TIMESTAMP'2024-10-22 15:00:00') and (p.published_at < TIMESTAMP'2026-10-22 15:00:00'))  (cost=1.29 rows=5.14) (actual time=0.0041..0.0102 rows=2.91 loops=6891)
                                    -> Select #5 (subquery in condition; dependent)
                                        -> Limit: 1 row(s)  (cost=0.35 rows=1) (actual time=0.00392..0.00392 rows=1 loops=1)
                                            -> Filter: (c.is_private = 0)  (cost=0.35 rows=1) (actual time=0.00383..0.00383 rows=1 loops=1)
                                                -> Single-row index lookup on c using PRIMARY (id = p.community_id)  (cost=0.35 rows=1) (actual time=0.00363..0.00363 rows=1 loops=1)
                            -> Single-row covering index lookup on b using domain_blocks_domain_unique (domain = a.domain)  (cost=2.75 rows=1) (actual time=95e-6..95e-6 rows=0 loops=20084)
```

## Q16B — Diffusione hashtag tra autori e domini — indice hashtag forzato (diagnostica)

Radice ms: `[266, 244, 212]`. Wall ms: `[269.74, 247.67, 216.11]`. Righe risultato: `10`.

```text
-> Sort: post_count DESC, ph.hashtag_id  (actual time=212..212 rows=10 loops=1)
    -> Stream results  (actual time=210..212 rows=10 loops=1)
        -> Group aggregate: count(0), count(distinct posts.actor_id), count(distinct actors.domain)  (actual time=210..212 rows=10 loops=1)
            -> Sort: ph.hashtag_id  (actual time=208..208 rows=9104 loops=1)
                -> Stream results  (cost=4126 rows=2.29e-6) (actual time=110..198 rows=9104 loops=1)
                    -> Filter: (ph.post_id = p.id)  (cost=4126 rows=2.29e-6) (actual time=110..197 rows=9104 loops=1)
                        -> Inner hash join (<hash>(ph.post_id)=<hash>(p.id))  (cost=4126 rows=2.29e-6) (actual time=110..196 rows=9104 loops=1)
                            -> Filter: (ph.hashtag_id in ('019fc806-7d4e-715b-8dd2-85520afba1e9','019fc832-28cb-736b-959a-869dc994435a','019fc806-7d50-72ce-9acf-d39093bc7ed8','019fd6cd-3552-70ba-8784-d43ef9f45de6','019ff7b5-9000-7299-97c8-89786db1f3cd','019fd0e4-ac6c-7228-a881-d196daa2002a','019fda3c-1c16-72c4-88f4-63649c606bb1','019fd0e4-ac49-712f-9eb4-42367b63a376','019ff81b-825d-712d-aad9-708c115668d7','019fd1c5-9092-7342-8945-ce11382f7f39'))  (cost=4886 rows=1.24) (actual time=0.125..14.2 rows=24580 loops=1)
                                -> Covering index range scan on ph using post_hashtags_hashtag_id_index over (hashtag_id = '019fc806-7d4e-715b-8dd2-85520afba1e9') OR (hashtag_id = '019fc806-7d50-72ce-9acf-d39093bc7ed8') OR (8 more)  (cost=4886 rows=38478) (actual time=0.124..7.24 rows=24580 loops=1)
                            -> Hash
                                -> Nested loop antijoin  (cost=3847 rows=0.0571) (actual time=0.0457..104 rows=20084 loops=1)
                                    -> Nested loop inner join  (cost=3847 rows=0.0571) (actual time=0.0444..101 rows=20084 loops=1)
                                        -> Filter: ((a.indexable = 1) and (a.discoverable = 1) and (a.remote_suspended = 0) and (a.`status` = 'active') and (a.deleted_at is null) and (a.`type` <> 'application'))  (cost=3845 rows=1) (actual time=0.0101..9.21 rows=6891 loops=1)
                                            -> Table scan on a  (cost=3845 rows=34516) (actual time=0.00929..8 rows=33045 loops=1)
                                        -> Filter: ((p.conversation_id is null) and ((p.community_id is null) or exists(select #5)))  (cost=1.29 rows=0.0571) (actual time=0.00434..0.0132 rows=2.91 loops=6891)
                                            -> Index lookup on p using posts_popular_remote_actor_index (status = 'published', visibility = 'public', actor_id = a.id), with index condition: ((p.published_at >= TIMESTAMP'2024-10-22 15:00:00') and (p.published_at < TIMESTAMP'2026-10-22 15:00:00'))  (cost=1.29 rows=5.14) (actual time=0.00428..0.013 rows=2.91 loops=6891)
                                            -> Select #5 (subquery in condition; dependent)
                                                -> Limit: 1 row(s)  (cost=0.35 rows=1) (actual time=0.00421..0.00425 rows=1 loops=1)
                                                    -> Filter: (c.is_private = 0)  (cost=0.35 rows=1) (actual time=0.00404..0.00404 rows=1 loops=1)
                                                        -> Single-row index lookup on c using PRIMARY (id = p.community_id)  (cost=0.35 rows=1) (actual time=0.00396..0.00396 rows=1 loops=1)
                                    -> Single-row covering index lookup on b using domain_blocks_domain_unique (domain = a.domain)  (cost=2.75 rows=1) (actual time=94.1e-6..94.1e-6 rows=0 loops=20084)
```

## Q13B — Ritmo orario: controllo su altra finestra storica

Radice ms: `[4.6, 4.32, 4.37]`. Wall ms: `[6.45, 4.66, 4.65]`. Righe risultato: `37`.

```text
-> Sort: hour_start, public_posts.is_local  (actual time=4.37..4.37 rows=37 loops=1)
    -> Table scan on <temporary>  (actual time=4.36..4.36 rows=37 loops=1)
        -> Aggregate using temporary table  (actual time=4.36..4.36 rows=37 loops=1)
            -> Nested loop antijoin  (cost=614 rows=6.32) (actual time=0.0313..4.11 rows=746 loops=1)
                -> Nested loop inner join  (cost=613 rows=6.32) (actual time=0.0301..3.95 rows=746 loops=1)
                    -> Filter: ((p.conversation_id is null) and ((p.community_id is null) or exists(select #5)))  (cost=569 rows=126) (actual time=0.0257..2.07 rows=1263 loops=1)
                        -> Index range scan on p using posts_visibility_status_published_at_index over (visibility = 'public' AND status = 'published' AND '2026-09-15 15:00:00' <= published_at < '2026-09-30 15:00:00'), with index condition: ((p.published_at >= TIMESTAMP'2026-09-15 15:00:00') and (p.`status` = 'published') and (p.visibility = 'public') and (p.published_at >= TIMESTAMP'2024-10-22 15:00:00') and (p.published_at < TIMESTAMP'2026-09-30 15:00:00'))  (cost=569 rows=1263) (actual time=0.0255..2.02 rows=1263 loops=1)
                        -> Select #5 (subquery in condition; dependent)
                            -> Limit: 1 row(s)  (cost=0.35 rows=1) (never executed)
                                -> Filter: (c.is_private = 0)  (cost=0.35 rows=1) (never executed)
                                    -> Single-row index lookup on c using PRIMARY (id = p.community_id)  (cost=0.35 rows=1) (never executed)
                    -> Filter: ((a.indexable = 1) and (a.discoverable = 1) and (a.remote_suspended = 0) and (a.`status` = 'active') and (a.deleted_at is null) and (a.`type` <> 'application'))  (cost=0.25 rows=0.05) (actual time=0.00141..0.00144 rows=0.591 loops=1263)
                        -> Single-row index lookup on a using PRIMARY (id = p.actor_id)  (cost=0.25 rows=1) (actual time=0.0013..0.00131 rows=1 loops=1263)
                -> Single-row index lookup on <subquery4> using <auto_distinct_key> (domain = a.domain)  (cost=2.06..2.06 rows=1) (actual time=149e-6..149e-6 rows=0 loops=746)
                    -> Materialize with deduplication  (cost=1.33..1.33 rows=1) (actual time=792e-6..792e-6 rows=0 loops=1)
                        -> Filter: (b.domain is not null)  (cost=1.1 rows=1) (actual time=499e-6..499e-6 rows=0 loops=1)
                            -> Covering index scan on b using domain_blocks_domain_unique  (cost=1.1 rows=1) (actual time=499e-6..499e-6 rows=0 loops=1)
```

## Q13C — Ritmo orario: finestra di 15 giorni più popolata del corpus ammesso

Radice ms: `[124, 127, 97.7]`. Wall ms: `[129.18, 128.41, 98.41]`. Righe risultato: `376`.

```text
-> Sort: hour_start, public_posts.is_local  (actual time=97.7..97.7 rows=376 loops=1)
    -> Table scan on <temporary>  (actual time=95.5..95.5 rows=376 loops=1)
        -> Aggregate using temporary table  (actual time=95.5..95.5 rows=376 loops=1)
            -> Nested loop antijoin  (cost=1130 rows=4.05) (actual time=0.0382..89.6 rows=16757 loops=1)
                -> Nested loop inner join  (cost=1129 rows=4.05) (actual time=0.0354..86.4 rows=16757 loops=1)
                    -> Filter: ((p.conversation_id is null) and ((p.community_id is null) or exists(select #5)))  (cost=1101 rows=80.9) (actual time=0.0312..65.9 rows=41718 loops=1)
                        -> Index lookup on p using posts_popular_remote_actor_index (status = 'published', visibility = 'public'), with index condition: ((p.published_at >= TIMESTAMP'2026-08-29 00:00:00') and (p.published_at >= TIMESTAMP'2024-10-22 15:00:00') and (p.published_at < TIMESTAMP'2026-09-13 00:00:00'))  (cost=1101 rows=21855) (actual time=0.031..64.6 rows=41718 loops=1)
                        -> Select #5 (subquery in condition; dependent)
                            -> Limit: 1 row(s)  (cost=0.35 rows=1) (never executed)
                                -> Filter: (c.is_private = 0)  (cost=0.35 rows=1) (never executed)
                                    -> Single-row index lookup on c using PRIMARY (id = p.community_id)  (cost=0.35 rows=1) (never executed)
                    -> Filter: ((a.indexable = 1) and (a.discoverable = 1) and (a.remote_suspended = 0) and (a.`status` = 'active') and (a.deleted_at is null) and (a.`type` <> 'application'))  (cost=0.25 rows=0.05) (actual time=422e-6..438e-6 rows=0.402 loops=41718)
                        -> Single-row index lookup on a using PRIMARY (id = p.actor_id)  (cost=0.25 rows=1) (actual time=317e-6..331e-6 rows=1 loops=41718)
                -> Single-row index lookup on <subquery4> using <auto_distinct_key> (domain = a.domain)  (cost=1.84..1.84 rows=1) (actual time=138e-6..138e-6 rows=0 loops=16757)
                    -> Materialize with deduplication  (cost=1.33..1.33 rows=1) (actual time=0.00225..0.00225 rows=0 loops=1)
                        -> Filter: (b.domain is not null)  (cost=1.1 rows=1) (actual time=0.00167..0.00167 rows=0 loops=1)
                            -> Covering index scan on b using domain_blocks_domain_unique  (cost=1.1 rows=1) (actual time=0.00154..0.00154 rows=0 loops=1)
```

## Q13D — Distribuzione giornaliera per scegliere una finestra rappresentativa

Radice ms: `[910, 114, 103]`. Wall ms: `[913.51, 116.79, 104.06]`. Righe risultato: `183`.

```text
-> Sort: `day`  (actual time=103..103 rows=183 loops=1)
    -> Table scan on <temporary>  (actual time=103..103 rows=183 loops=1)
        -> Aggregate using temporary table  (actual time=103..103 rows=183 loops=1)
            -> Nested loop antijoin  (cost=1204 rows=12.1) (actual time=0.0395..102 rows=20084 loops=1)
                -> Nested loop inner join  (cost=1202 rows=12.1) (actual time=0.037..98 rows=20084 loops=1)
                    -> Filter: ((p.conversation_id is null) and ((p.community_id is null) or exists(select #5)))  (cost=1117 rows=243) (actual time=0.0305..75.4 rows=48751 loops=1)
                        -> Index lookup on p using posts_popular_remote_actor_index (status = 'published', visibility = 'public'), with index condition: ((p.published_at >= TIMESTAMP'2024-10-22 15:00:00') and (p.published_at < TIMESTAMP'2026-10-22 15:00:00'))  (cost=1117 rows=21855) (actual time=0.0302..73.9 rows=48751 loops=1)
                        -> Select #5 (subquery in condition; dependent)
                            -> Limit: 1 row(s)  (cost=0.35 rows=1) (actual time=0.00279..0.00283 rows=1 loops=1)
                                -> Filter: (c.is_private = 0)  (cost=0.35 rows=1) (actual time=0.00262..0.00262 rows=1 loops=1)
                                    -> Single-row index lookup on c using PRIMARY (id = p.community_id)  (cost=0.35 rows=1) (actual time=0.0025..0.0025 rows=1 loops=1)
                    -> Filter: ((a.indexable = 1) and (a.discoverable = 1) and (a.remote_suspended = 0) and (a.`status` = 'active') and (a.deleted_at is null) and (a.`type` <> 'application'))  (cost=0.25 rows=0.05) (actual time=393e-6..410e-6 rows=0.412 loops=48751)
                        -> Single-row index lookup on a using PRIMARY (id = p.actor_id)  (cost=0.25 rows=1) (actual time=292e-6..306e-6 rows=1 loops=48751)
                -> Single-row index lookup on <subquery4> using <auto_distinct_key> (domain = a.domain)  (cost=2.64..2.64 rows=1) (actual time=139e-6..139e-6 rows=0 loops=20084)
                    -> Materialize with deduplication  (cost=1.33..1.33 rows=1) (actual time=0.00204..0.00204 rows=0 loops=1)
                        -> Filter: (b.domain is not null)  (cost=1.1 rows=1) (actual time=0.00142..0.00142 rows=0 loops=1)
                            -> Covering index scan on b using domain_blocks_domain_unique  (cost=1.1 rows=1) (actual time=0.00137..0.00137 rows=0 loops=1)
```

## Parametri hashtag del campione

I dieci ID di Q02 usati in Q03, Q16 e nelle rispettive varianti:

```text
'019fc806-7d4e-715b-8dd2-85520afba1e9', '019fc832-28cb-736b-959a-869dc994435a', '019fc806-7d50-72ce-9acf-d39093bc7ed8', '019fd6cd-3552-70ba-8784-d43ef9f45de6', '019ff7b5-9000-7299-97c8-89786db1f3cd', '019fd0e4-ac6c-7228-a881-d196daa2002a', '019fda3c-1c16-72c4-88f4-63649c606bb1', '019fd0e4-ac49-712f-9eb4-42367b63a376', '019ff81b-825d-712d-aad9-708c115668d7', '019fd1c5-9092-7342-8945-ce11382f7f39'
```

## Sprint 1 — SQL implementato e verifica del 22 ottobre 2026

Misure separate dal benchmark iniziale. Le query sono generate da `StatsQuery`;
nessuna cache dei risultati. Tre esecuzioni ANALYZE per query; sotto sono
conservati SQL, binding e piano finale. Policy aggiuntive impostate solo nel
processo di verifica e con cache policy in memoria, senza cambiare l'istanza.
PHPUnit non eseguito su richiesta dell'utente.

L'istanza usa `APP_TIMEZONE=Europe/Helsinki` e connessione principale SYSTEM.
Stats legge i TIMESTAMP MySQL su una connessione allo stesso database con
sessione `+00:00`, senza cambiare la sessione principale (SYSTEM prima/dopo).
SQLite converte gli orologi applicativi in UTC tramite una funzione locale
registrata dalla query; i filtri temporali rispettano l'orologio persistito.
Non sono richieste tabelle MySQL dei fusi orari né servizi esterni.

### Policy corrente (CW disattivati, nessun blocco)

#### Ritmo

Tempi radice ms: `[4.74, 4.39, 4.4]`.

```sql
select DATE_FORMAT(p.published_at, '%Y-%m-%dT%H:00:00Z') AS bucket, a.is_local, COUNT(*) AS count from `posts` as `p` inner join `actors` as `a` on `a`.`id` = `p`.`actor_id` where `a`.`status` = ? and `a`.`remote_suspended` = ? and `a`.`deleted_at` is null and `a`.`discoverable` = ? and `a`.`indexable` = ? and `a`.`type` <> ? and 1 = 1 and `p`.`status` = ? and `p`.`visibility` = ? and `p`.`conversation_id` is null and `p`.`published_at` >= ? and `p`.`published_at` < ? and (`p`.`community_id` is null or exists (select 1 from `communities` as `c` where `c`.`id` = `p`.`community_id` and `c`.`is_private` = ?)) group by `bucket`, `a`.`is_local` order by `bucket` asc
```

Binding nell'ordine: `["active", false, true, true, "application", "published", "public", "2026-10-07T16:00:00.000000Z", "2026-10-22T16:00:00.000000Z", false]`.

```text
-> Sort: bucket, a.is_local  (actual time=4.4..4.4 rows=55 loops=1)
    -> Table scan on <temporary>  (actual time=4.38..4.39 rows=55 loops=1)
        -> Aggregate using temporary table  (actual time=4.38..4.38 rows=55 loops=1)
            -> Nested loop inner join  (cost=661 rows=6.78) (actual time=0.0298..4.13 rows=819 loops=1)
                -> Filter: ((p.conversation_id is null) and ((p.community_id is null) or exists(select #2)))  (cost=610 rows=136) (actual time=0.0269..2.15 rows=1356 loops=1)
                    -> Index range scan on p using posts_visibility_status_published_at_index over (visibility = 'public' AND status = 'published' AND '2026-10-07 16:00:00' <= published_at < '2026-10-22 16:00:00'), with index condition: ((p.`status` = 'published') and (p.visibility = 'public') and (p.published_at >= TIMESTAMP'2026-10-07 16:00:00') and (p.published_at < TIMESTAMP'2026-10-22 16:00:00'))  (cost=610 rows=1356) (actual time=0.0268..2.1 rows=1356 loops=1)
                    -> Select #2 (subquery in condition; dependent)
                        -> Limit: 1 row(s)  (cost=0.35 rows=1) (never executed)
                            -> Filter: (c.is_private = 0)  (cost=0.35 rows=1) (never executed)
                                -> Single-row index lookup on c using PRIMARY (id = p.community_id)  (cost=0.35 rows=1) (never executed)
                -> Filter: ((a.indexable = 1) and (a.discoverable = 1) and (a.remote_suspended = 0) and (a.`status` = 'active') and (a.deleted_at is null) and (a.`type` <> 'application'))  (cost=0.275 rows=0.05) (actual time=0.00138..0.0014 rows=0.604 loops=1356)
                    -> Single-row index lookup on a using PRIMARY (id = p.actor_id)  (cost=0.275 rows=1) (actual time=0.00126..0.00127 rows=1 loops=1356)
```

#### Lingue, UNION ALL post/eventi

Tempi radice ms: `[117.0, 116.0, 110.0]`.

```sql
(select ? AS kind, DATE_SUB(DATE(p.published_at), INTERVAL WEEKDAY(p.published_at) DAY) AS week, a.is_local,
                COALESCE(NULLIF(LOWER(TRIM(p.language)), ''), 'und') AS language,
                COUNT(*) AS count from `posts` as `p` inner join `actors` as `a` on `a`.`id` = `p`.`actor_id` where `a`.`status` = ? and `a`.`remote_suspended` = ? and `a`.`deleted_at` is null and `a`.`discoverable` = ? and `a`.`indexable` = ? and `a`.`type` <> ? and 1 = 1 and `p`.`status` = ? and `p`.`visibility` = ? and `p`.`conversation_id` is null and `p`.`published_at` >= ? and `p`.`published_at` < ? and (`p`.`community_id` is null or exists (select 1 from `communities` as `c` where `c`.`id` = `p`.`community_id` and `c`.`is_private` = ?)) group by `week`, `a`.`is_local`, COALESCE(NULLIF(LOWER(TRIM(p.language)), ''), 'und')) union all (select ? AS kind, DATE_SUB(DATE(e.published_at), INTERVAL WEEKDAY(e.published_at) DAY) AS week, a.is_local,
                COALESCE(NULLIF(LOWER(TRIM(e.language)), ''), 'und') AS language,
                COUNT(*) AS count from `events` as `e` inner join `actors` as `a` on `a`.`id` = `e`.`actor_id` where `a`.`status` = ? and `a`.`remote_suspended` = ? and `a`.`deleted_at` is null and `a`.`discoverable` = ? and `a`.`indexable` = ? and `a`.`type` <> ? and 1 = 1 and `e`.`visibility` = ? and `e`.`deleted_at` is null and `e`.`status` in (?, ?, ?) and `e`.`published_at` >= ? and `e`.`published_at` < ? group by `week`, `a`.`is_local`, COALESCE(NULLIF(LOWER(TRIM(e.language)), ''), 'und'))
```

Binding nell'ordine: `["post", "active", false, true, true, "application", "published", "public", "2024-10-22T16:00:00.000000Z", "2026-10-22T16:00:00.000000Z", false, "event", "active", false, true, true, "application", "public", "scheduled", "tentative", "postponed", "2024-10-22T16:00:00.000000Z", "2026-10-22T16:00:00.000000Z"]`.

```text
-> Append  (actual time=109..110 rows=114 loops=1)
    -> Stream results  (actual time=109..109 rows=100 loops=1)
        -> Table scan on <temporary>  (actual time=109..109 rows=100 loops=1)
            -> Aggregate using temporary table  (actual time=109..109 rows=100 loops=1)
                -> Nested loop inner join  (cost=1208 rows=12.1) (actual time=0.05..104 rows=20084 loops=1)
                    -> Filter: ((p.conversation_id is null) and ((p.community_id is null) or exists(select #2)))  (cost=1117 rows=243) (actual time=0.0435..80.7 rows=48751 loops=1)
                        -> Index lookup on p using posts_popular_remote_actor_index (status = 'published', visibility = 'public'), with index condition: ((p.published_at >= TIMESTAMP'2024-10-22 16:00:00') and (p.published_at < TIMESTAMP'2026-10-22 16:00:00'))  (cost=1117 rows=21855) (actual time=0.0433..79.1 rows=48751 loops=1)
                        -> Select #2 (subquery in condition; dependent)
                            -> Limit: 1 row(s)  (cost=0.35 rows=1) (actual time=0.00442..0.00442 rows=1 loops=1)
                                -> Filter: (c.is_private = 0)  (cost=0.35 rows=1) (actual time=0.00425..0.00425 rows=1 loops=1)
                                    -> Single-row index lookup on c using PRIMARY (id = p.community_id)  (cost=0.35 rows=1) (actual time=0.00408..0.00408 rows=1 loops=1)
                    -> Filter: ((a.indexable = 1) and (a.discoverable = 1) and (a.remote_suspended = 0) and (a.`status` = 'active') and (a.deleted_at is null) and (a.`type` <> 'application'))  (cost=0.275 rows=0.05) (actual time=409e-6..426e-6 rows=0.412 loops=48751)
                        -> Single-row index lookup on a using PRIMARY (id = p.actor_id)  (cost=0.275 rows=1) (actual time=300e-6..314e-6 rows=1 loops=48751)
    -> Stream results  (actual time=0.19..0.192 rows=14 loops=1)
        -> Table scan on <temporary>  (actual time=0.189..0.189 rows=14 loops=1)
            -> Aggregate using temporary table  (actual time=0.189..0.189 rows=14 loops=1)
                -> Nested loop inner join  (cost=20 rows=0.0811) (actual time=0.0172..0.159 rows=76 loops=1)
                    -> Filter: ((e.visibility = 'public') and (e.deleted_at is null) and (e.`status` in ('scheduled','tentative','postponed')) and (e.published_at >= TIMESTAMP'2024-10-22 16:00:00') and (e.published_at < TIMESTAMP'2026-10-22 16:00:00') and (e.actor_id is not null))  (cost=19.4 rows=1.62) (actual time=0.011..0.0784 rows=145 loops=1)
                        -> Table scan on e  (cost=19.4 rows=149) (actual time=0.00896..0.0519 rows=151 loops=1)
                    -> Filter: ((a.indexable = 1) and (a.discoverable = 1) and (a.remote_suspended = 0) and (a.`status` = 'active') and (a.deleted_at is null) and (a.`type` <> 'application'))  (cost=0.278 rows=0.05) (actual time=480e-6..501e-6 rows=0.524 loops=145)
                        -> Single-row index lookup on a using PRIMARY (id = e.actor_id)  (cost=0.278 rows=1) (actual time=363e-6..380e-6 rows=1 loops=145)
```

### Policy di verifica (CW attivi, tag nsfw escluso, mastodon.social bloccato)

#### Ritmo

Tempi radice ms: `[5.6, 5.33, 5.59]`.

```sql
select DATE_FORMAT(p.published_at, '%Y-%m-%dT%H:00:00Z') AS bucket, a.is_local, COUNT(*) AS count from `posts` as `p` inner join `actors` as `a` on `a`.`id` = `p`.`actor_id` where `a`.`status` = ? and `a`.`remote_suspended` = ? and `a`.`deleted_at` is null and `a`.`discoverable` = ? and `a`.`indexable` = ? and `a`.`type` <> ? and `a`.`domain` not in (?) and `a`.`uri` <> ? and `a`.`uri` not like ? and `a`.`uri` not like ? and `a`.`uri` not like ? and `a`.`uri` not like ? and `a`.`uri` <> ? and `a`.`uri` not like ? and `a`.`uri` not like ? and `a`.`uri` not like ? and `a`.`uri` not like ? and `p`.`status` = ? and `p`.`visibility` = ? and `p`.`conversation_id` is null and `p`.`published_at` >= ? and `p`.`published_at` < ? and (`p`.`community_id` is null or exists (select 1 from `communities` as `c` where `c`.`id` = `p`.`community_id` and `c`.`is_private` = ?)) and (`p`.`content_warning` is null or `p`.`content_warning` = ?) and not exists (select 1 from `post_hashtags` as `ph` inner join `hashtags` as `h` on `h`.`id` = `ph`.`hashtag_id` where `ph`.`post_id` = `p`.`id` and `h`.`name` in (?)) group by `bucket`, `a`.`is_local` order by `bucket` asc
```

Binding nell'ordine: `["active", false, true, true, "application", "mastodon.social", "https://mastodon.social", "https://mastodon.social/%", "https://mastodon.social:%", "https://mastodon.social?%", "https://mastodon.social#%", "http://mastodon.social", "http://mastodon.social/%", "http://mastodon.social:%", "http://mastodon.social?%", "http://mastodon.social#%", "published", "public", "2026-10-07T16:00:00.000000Z", "2026-10-22T16:00:00.000000Z", false, "", "nsfw"]`.

```text
-> Sort: bucket, a.is_local  (actual time=5.59..5.59 rows=53 loops=1)
    -> Table scan on <temporary>  (actual time=5.58..5.58 rows=53 loops=1)
        -> Aggregate using temporary table  (actual time=5.58..5.58 rows=53 loops=1)
            -> Nested loop inner join  (cost=640 rows=7.75) (actual time=0.114..5.33 rows=754 loops=1)
                -> Nested loop antijoin  (cost=618 rows=155) (actual time=0.11..2.86 rows=1350 loops=1)
                    -> Filter: ((p.conversation_id is null) and ((p.community_id is null) or exists(select #2)) and ((p.content_warning is null) or (p.content_warning = '')))  (cost=599 rows=25.8) (actual time=0.03..2.32 rows=1350 loops=1)
                        -> Index range scan on p using posts_visibility_status_published_at_index over (visibility = 'public' AND status = 'published' AND '2026-10-07 16:00:00' <= published_at < '2026-10-22 16:00:00'), with index condition: ((p.`status` = 'published') and (p.visibility = 'public') and (p.published_at >= TIMESTAMP'2026-10-07 16:00:00') and (p.published_at < TIMESTAMP'2026-10-22 16:00:00'))  (cost=599 rows=1356) (actual time=0.0298..2.26 rows=1356 loops=1)
                        -> Select #2 (subquery in condition; dependent)
                            -> Limit: 1 row(s)  (cost=0.35 rows=1) (never executed)
                                -> Filter: (c.is_private = 0)  (cost=0.35 rows=1) (never executed)
                                    -> Single-row index lookup on c using PRIMARY (id = p.community_id)  (cost=0.35 rows=1) (never executed)
                    -> Single-row index lookup on <subquery3> using <auto_distinct_key> (post_id = p.id)  (cost=5.65..5.65 rows=1) (actual time=349e-6..349e-6 rows=0 loops=1350)
                        -> Materialize with deduplication  (cost=3.57..3.57 rows=6.01) (actual time=0.0789..0.0789 rows=162 loops=1)
                            -> Filter: (ph.post_id is not null)  (cost=2.18 rows=6.01) (actual time=0.00625..0.0473 rows=162 loops=1)
                                -> Nested loop inner join  (cost=2.18 rows=6.01) (actual time=0.00608..0.0426 rows=162 loops=1)
                                    -> Single-row covering index lookup on h using hashtags_name_unique (name = 'nsfw')  (cost=1.1 rows=1) (actual time=0.002..0.00204 rows=1 loops=1)
                                    -> Covering index lookup on ph using post_hashtags_hashtag_id_index (hashtag_id = h.id)  (cost=1.08 rows=6.01) (actual time=0.00404..0.0353 rows=162 loops=1)
                -> Filter: ((a.indexable = 1) and (a.discoverable = 1) and (a.remote_suspended = 0) and (a.`status` = 'active') and (a.deleted_at is null) and (a.`type` <> 'application') and (a.domain <> 'mastodon.social') and (a.`uri` <> 'https://mastodon.social') and (not((a.`uri` like 'https://mastodon.social/%'))) and (not((a.`uri` like 'https://mastodon.social:%'))) and (not((a.`uri` like 'https://mastodon.social?%'))) and (not((a.`uri` like 'https://mastodon.social#%'))) and (a.`uri` <> 'http://mastodon.social') and (not((a.`uri` like 'http://mastodon.social/%'))) and (not((a.`uri` like 'http://mastodon.social:%'))) and (not((a.`uri` like 'http://mastodon.social?%'))) and (not((a.`uri` like 'http://mastodon.social#%'))))  (cost=0.275 rows=0.05) (actual time=0.00175..0.00177 rows=0.559 loops=1350)
                    -> Single-row index lookup on a using PRIMARY (id = p.actor_id)  (cost=0.275 rows=1) (actual time=0.00139..0.0014 rows=1 loops=1350)
```

#### Lingue, UNION ALL post/eventi

Tempi radice ms: `[138.0, 135.0, 147.0]`.

```sql
(select ? AS kind, DATE_SUB(DATE(p.published_at), INTERVAL WEEKDAY(p.published_at) DAY) AS week, a.is_local,
                COALESCE(NULLIF(LOWER(TRIM(p.language)), ''), 'und') AS language,
                COUNT(*) AS count from `posts` as `p` inner join `actors` as `a` on `a`.`id` = `p`.`actor_id` where `a`.`status` = ? and `a`.`remote_suspended` = ? and `a`.`deleted_at` is null and `a`.`discoverable` = ? and `a`.`indexable` = ? and `a`.`type` <> ? and `a`.`domain` not in (?) and `a`.`uri` <> ? and `a`.`uri` not like ? and `a`.`uri` not like ? and `a`.`uri` not like ? and `a`.`uri` not like ? and `a`.`uri` <> ? and `a`.`uri` not like ? and `a`.`uri` not like ? and `a`.`uri` not like ? and `a`.`uri` not like ? and `p`.`status` = ? and `p`.`visibility` = ? and `p`.`conversation_id` is null and `p`.`published_at` >= ? and `p`.`published_at` < ? and (`p`.`community_id` is null or exists (select 1 from `communities` as `c` where `c`.`id` = `p`.`community_id` and `c`.`is_private` = ?)) and (`p`.`content_warning` is null or `p`.`content_warning` = ?) and not exists (select 1 from `post_hashtags` as `ph` inner join `hashtags` as `h` on `h`.`id` = `ph`.`hashtag_id` where `ph`.`post_id` = `p`.`id` and `h`.`name` in (?)) group by `week`, `a`.`is_local`, COALESCE(NULLIF(LOWER(TRIM(p.language)), ''), 'und')) union all (select ? AS kind, DATE_SUB(DATE(e.published_at), INTERVAL WEEKDAY(e.published_at) DAY) AS week, a.is_local,
                COALESCE(NULLIF(LOWER(TRIM(e.language)), ''), 'und') AS language,
                COUNT(*) AS count from `events` as `e` inner join `actors` as `a` on `a`.`id` = `e`.`actor_id` where `a`.`status` = ? and `a`.`remote_suspended` = ? and `a`.`deleted_at` is null and `a`.`discoverable` = ? and `a`.`indexable` = ? and `a`.`type` <> ? and `a`.`domain` not in (?) and `a`.`uri` <> ? and `a`.`uri` not like ? and `a`.`uri` not like ? and `a`.`uri` not like ? and `a`.`uri` not like ? and `a`.`uri` <> ? and `a`.`uri` not like ? and `a`.`uri` not like ? and `a`.`uri` not like ? and `a`.`uri` not like ? and `e`.`visibility` = ? and `e`.`deleted_at` is null and `e`.`status` in (?, ?, ?) and `e`.`published_at` >= ? and `e`.`published_at` < ? and `e`.`sensitive` = ? and not exists (select 1 from `event_hashtags` as `ph` inner join `hashtags` as `h` on `h`.`id` = `ph`.`hashtag_id` where `ph`.`event_id` = `e`.`id` and `h`.`name` in (?)) group by `week`, `a`.`is_local`, COALESCE(NULLIF(LOWER(TRIM(e.language)), ''), 'und'))
```

Binding nell'ordine: `["post", "active", false, true, true, "application", "mastodon.social", "https://mastodon.social", "https://mastodon.social/%", "https://mastodon.social:%", "https://mastodon.social?%", "https://mastodon.social#%", "http://mastodon.social", "http://mastodon.social/%", "http://mastodon.social:%", "http://mastodon.social?%", "http://mastodon.social#%", "published", "public", "2024-10-22T16:00:00.000000Z", "2026-10-22T16:00:00.000000Z", false, "", "nsfw", "event", "active", false, true, true, "application", "mastodon.social", "https://mastodon.social", "https://mastodon.social/%", "https://mastodon.social:%", "https://mastodon.social?%", "https://mastodon.social#%", "http://mastodon.social", "http://mastodon.social/%", "http://mastodon.social:%", "http://mastodon.social?%", "http://mastodon.social#%", "public", "scheduled", "tentative", "postponed", "2024-10-22T16:00:00.000000Z", "2026-10-22T16:00:00.000000Z", false, "nsfw"]`.

```text
-> Append  (actual time=146..147 rows=105 loops=1)
    -> Stream results  (actual time=146..146 rows=91 loops=1)
        -> Table scan on <temporary>  (actual time=146..146 rows=91 loops=1)
            -> Aggregate using temporary table  (actual time=146..146 rows=91 loops=1)
                -> Nested loop inner join  (cost=1170 rows=13.9) (actual time=0.121..141 rows=16597 loops=1)
                    -> Nested loop antijoin  (cost=1130 rows=277) (actual time=0.113..110 rows=48478 loops=1)
                        -> Filter: ((p.conversation_id is null) and ((p.community_id is null) or exists(select #2)) and ((p.content_warning is null) or (p.content_warning = '')))  (cost=1097 rows=46.1) (actual time=0.0379..93.2 rows=48484 loops=1)
                            -> Index lookup on p using posts_popular_remote_actor_index (status = 'published', visibility = 'public'), with index condition: ((p.published_at >= TIMESTAMP'2024-10-22 16:00:00') and (p.published_at < TIMESTAMP'2026-10-22 16:00:00'))  (cost=1097 rows=21855) (actual time=0.0377..91 rows=48751 loops=1)
                            -> Select #2 (subquery in condition; dependent)
                                -> Limit: 1 row(s)  (cost=0.35 rows=1) (actual time=0.00675..0.00679 rows=1 loops=1)
                                    -> Filter: (c.is_private = 0)  (cost=0.35 rows=1) (actual time=0.00654..0.00654 rows=1 loops=1)
                                        -> Single-row index lookup on c using PRIMARY (id = p.community_id)  (cost=0.35 rows=1) (actual time=0.00633..0.00633 rows=1 loops=1)
                        -> Single-row index lookup on <subquery3> using <auto_distinct_key> (post_id = p.id)  (cost=5.61..5.61 rows=1) (actual time=301e-6..301e-6 rows=124e-6 loops=48484)
                            -> Materialize with deduplication  (cost=3.57..3.57 rows=6.01) (actual time=0.0742..0.0742 rows=162 loops=1)
                                -> Filter: (ph.post_id is not null)  (cost=2.18 rows=6.01) (actual time=0.00779..0.0448 rows=162 loops=1)
                                    -> Nested loop inner join  (cost=2.18 rows=6.01) (actual time=0.00771..0.0404 rows=162 loops=1)
                                        -> Single-row covering index lookup on h using hashtags_name_unique (name = 'nsfw')  (cost=1.1 rows=1) (actual time=0.00154..0.00154 rows=1 loops=1)
                                        -> Covering index lookup on ph using post_hashtags_hashtag_id_index (hashtag_id = h.id)  (cost=1.08 rows=6.01) (actual time=0.00608..0.0344 rows=162 loops=1)
                    -> Filter: ((a.indexable = 1) and (a.discoverable = 1) and (a.remote_suspended = 0) and (a.`status` = 'active') and (a.deleted_at is null) and (a.`type` <> 'application') and (a.domain <> 'mastodon.social') and (a.`uri` <> 'https://mastodon.social') and (not((a.`uri` like 'https://mastodon.social/%'))) and (not((a.`uri` like 'https://mastodon.social:%'))) and (not((a.`uri` like 'https://mastodon.social?%'))) and (not((a.`uri` like 'https://mastodon.social#%'))) and (a.`uri` <> 'http://mastodon.social') and (not((a.`uri` like 'http://mastodon.social/%'))) and (not((a.`uri` like 'http://mastodon.social:%'))) and (not((a.`uri` like 'http://mastodon.social?%'))) and (not((a.`uri` like 'http://mastodon.social#%'))))  (cost=0.275 rows=0.05) (actual time=571e-6..586e-6 rows=0.342 loops=48478)
                        -> Single-row index lookup on a using PRIMARY (id = p.actor_id)  (cost=0.275 rows=1) (actual time=310e-6..325e-6 rows=1 loops=48478)
    -> Stream results  (actual time=0.452..0.454 rows=14 loops=1)
        -> Table scan on <temporary>  (actual time=0.448..0.449 rows=14 loops=1)
            -> Aggregate using temporary table  (actual time=0.448..0.448 rows=14 loops=1)
                -> Nested loop inner join  (cost=20.9 rows=0.05) (actual time=0.043..0.397 rows=76 loops=1)
                    -> Nested loop antijoin  (cost=20.5 rows=1) (actual time=0.0352..0.283 rows=145 loops=1)
                        -> Filter: ((e.`sensitive` = 0) and (e.visibility = 'public') and (e.deleted_at is null) and (e.`status` in ('scheduled','tentative','postponed')) and (e.published_at >= TIMESTAMP'2024-10-22 16:00:00') and (e.published_at < TIMESTAMP'2026-10-22 16:00:00') and (e.actor_id is not null))  (cost=19.4 rows=1) (actual time=0.0256..0.101 rows=145 loops=1)
                            -> Table scan on e  (cost=19.4 rows=149) (actual time=0.0231..0.0708 rows=151 loops=1)
                        -> Nested loop inner join  (cost=1.45 rows=1) (actual time=0.0012..0.0012 rows=0 loops=145)
                            -> Single-row covering index lookup on h using hashtags_name_unique (name = 'nsfw')  (cost=1.1 rows=1) (actual time=66.4e-6..81.6e-6 rows=1 loops=145)
                            -> Single-row covering index lookup on ph using PRIMARY (event_id = e.id, hashtag_id = h.id)  (cost=0.35 rows=1) (actual time=0.00102..0.00102 rows=0 loops=145)
                    -> Filter: ((a.indexable = 1) and (a.discoverable = 1) and (a.remote_suspended = 0) and (a.`status` = 'active') and (a.deleted_at is null) and (a.`type` <> 'application') and (a.domain <> 'mastodon.social') and (a.`uri` <> 'https://mastodon.social') and (not((a.`uri` like 'https://mastodon.social/%'))) and (not((a.`uri` like 'https://mastodon.social:%'))) and (not((a.`uri` like 'https://mastodon.social?%'))) and (not((a.`uri` like 'https://mastodon.social#%'))) and (a.`uri` <> 'http://mastodon.social') and (not((a.`uri` like 'http://mastodon.social/%'))) and (not((a.`uri` like 'http://mastodon.social:%'))) and (not((a.`uri` like 'http://mastodon.social?%'))) and (not((a.`uri` like 'http://mastodon.social#%'))))  (cost=0.28 rows=0.05) (actual time=707e-6..728e-6 rows=0.524 loops=145)
                        -> Single-row index lookup on a using PRIMARY (id = e.actor_id)  (cost=0.28 rows=1) (actual time=382e-6..393e-6 rows=1 loops=145)
```
