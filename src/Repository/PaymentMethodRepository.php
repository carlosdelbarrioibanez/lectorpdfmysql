<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final class PaymentMethodRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function getIds(int $limit = 200): array
    {
        $statement = $this->pdo->prepare('SELECT `id_forma_pago` FROM `formas_pago` ORDER BY `id_forma_pago` ASC LIMIT :limit');
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id_forma_pago'],
                'label' => 'Forma de pago #' . $row['id_forma_pago'],
            ],
            $statement->fetchAll()
        );
    }
}
