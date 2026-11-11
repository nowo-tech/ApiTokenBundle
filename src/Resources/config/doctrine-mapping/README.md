# Doctrine XML mapping

`ApiToken.orm.xml` maps the plain domain class `Nowo\ApiTokenBundle\Domain\ApiToken` (contract §5.1). The bundle
prepends it on `doctrine.entity_manager` while `enabled` and `doctrine.auto_mapping` are true and `doctrine.mapping` is
`xml` (the default). With `doctrine.mapping: attribute` the attribute-mapped twin
`Nowo\ApiTokenBundle\Infrastructure\Doctrine\Entity\ApiTokenRecord` is registered instead (same table, same columns,
tested). DDD hosts set `auto_mapping: false` and register this directory (type `xml`, prefix
`Nowo\ApiTokenBundle\Domain`) on their own entity manager.

The directory is deliberately not `Resources/config/doctrine`: DoctrineBundle's bundle auto-mapping scans that path
and would map the file under the wrong namespace in hosts that keep the bundle disabled.

The table and index names get `doctrine.table_prefix`, and the identifier columns a binary collation on MySQL /
MariaDB, through `ApiTokenMetadataListener`. The bundle writes only through DBAL; the mapping exists for the schema and
for read-only host queries.
