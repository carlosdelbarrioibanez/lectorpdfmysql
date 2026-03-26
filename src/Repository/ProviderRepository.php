<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final class ProviderRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function getIds(int $limit = 200): array
    {
        $statement = $this->pdo->prepare('SELECT `id_proveedor` FROM `proveedores` ORDER BY `id_proveedor` ASC LIMIT :limit');
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id_proveedor'],
                'label' => 'Proveedor #' . $row['id_proveedor'],
            ],
            $statement->fetchAll()
        );
    }
}
