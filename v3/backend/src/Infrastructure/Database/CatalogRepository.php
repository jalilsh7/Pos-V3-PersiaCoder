<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Infrastructure\Database;

use PDO;

final readonly class CatalogRepository
{
    public function __construct(private PDO $connection) {}

    public function findCategoryIdByPublicId(int $businessId, string $publicId): ?int
    {
        $statement = $this->connection->prepare(
            'SELECT id FROM pos_categories WHERE business_id = :business_id AND public_id = :public_id AND is_active = 1 LIMIT 1'
        );
        $statement->execute(['business_id' => $businessId, 'public_id' => $publicId]);
        $value = $statement->fetchColumn();
        return $value === false ? null : (int) $value;
    }

    public function listCategories(int $businessId): array
    {
        $statement = $this->connection->prepare(
            'SELECT c.public_id, c.name, c.sort_order, c.is_active, p.public_id AS parent_public_id,
                    m.public_id AS image_public_id
             FROM pos_categories c
             LEFT JOIN pos_categories p ON p.id = c.parent_id AND p.business_id = c.business_id
             LEFT JOIN pos_category_media cm ON cm.category_id = c.id AND cm.business_id = c.business_id
             LEFT JOIN pos_media_assets m ON m.id = cm.media_id AND m.business_id = cm.business_id
             WHERE c.business_id = :business_id
             ORDER BY c.sort_order ASC, c.name ASC, c.id ASC'
        );
        $statement->execute(['business_id' => $businessId]);
        return array_map(static fn (array $row): array => [
            'id' => (string) $row['public_id'],
            'name' => (string) $row['name'],
            'parent_id' => $row['parent_public_id'] === null ? null : (string) $row['parent_public_id'],
            'sort_order' => (int) $row['sort_order'],
            'is_active' => (int) $row['is_active'] === 1,
            'image' => $row['image_public_id'] === null ? null : [
                'id' => (string) $row['image_public_id'],
                'url' => '/api/v1/media/' . (string) $row['image_public_id'],
            ],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function createCategory(
        int $businessId,
        string $publicId,
        string $name,
        ?int $parentId,
        int $sortOrder,
    ): void {
        $statement = $this->connection->prepare(
            'INSERT INTO pos_categories (business_id, public_id, parent_id, name, sort_order, is_active)
             VALUES (:business_id, :public_id, :parent_id, :name, :sort_order, 1)'
        );
        $statement->execute([
            'business_id' => $businessId,
            'public_id' => $publicId,
            'parent_id' => $parentId,
            'name' => $name,
            'sort_order' => $sortOrder,
        ]);
    }

    public function listProducts(int $businessId, ?string $search, ?string $categoryPublicId, int $limit): array
    {
        $sql = 'SELECT p.public_id, p.name, p.sku, p.product_type, p.base_price_amount,
                       p.tax_rate_bps, p.is_active, c.public_id AS category_public_id,
                       c.name AS category_name, m.public_id AS image_public_id
                FROM pos_products p
                LEFT JOIN pos_categories c ON c.id = p.category_id AND c.business_id = p.business_id
                LEFT JOIN pos_product_media pm ON pm.product_id = p.id AND pm.business_id = p.business_id
                LEFT JOIN pos_media_assets m ON m.id = pm.media_id AND m.business_id = pm.business_id
                WHERE p.business_id = :business_id AND p.archived_at IS NULL';
        $parameters = ['business_id' => $businessId];
        if ($search !== null && $search !== '') {
            $sql .= ' AND (p.name LIKE :search_name OR p.sku LIKE :search_sku)';
            $parameters['search_name'] = '%' . $search . '%';
            $parameters['search_sku'] = '%' . $search . '%';
        }
        if ($categoryPublicId !== null) {
            $sql .= ' AND c.public_id = :category_public_id';
            $parameters['category_public_id'] = $categoryPublicId;
        }
        $sql .= ' ORDER BY p.name ASC, p.id ASC LIMIT :limit';
        $statement = $this->connection->prepare($sql);
        foreach ($parameters as $key => $value) {
            if ($key === 'business_id') {
                continue;
            }
            $statement->bindValue(':' . $key, $value, PDO::PARAM_STR);
        }
        $statement->bindValue(':business_id', $businessId, PDO::PARAM_INT);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();
        return array_map(static fn (array $row): array => [
            'id' => (string) $row['public_id'],
            'name' => (string) $row['name'],
            'sku' => $row['sku'] === null ? null : (string) $row['sku'],
            'product_type' => (string) $row['product_type'],
            'base_price_amount' => (int) $row['base_price_amount'],
            'tax_rate_bps' => (int) $row['tax_rate_bps'],
            'is_active' => (int) $row['is_active'] === 1,
            'category' => $row['category_public_id'] === null ? null : [
                'id' => (string) $row['category_public_id'],
                'name' => (string) $row['category_name'],
            ],
            'image' => $row['image_public_id'] === null ? null : [
                'id' => (string) $row['image_public_id'],
                'url' => '/api/v1/media/' . (string) $row['image_public_id'],
            ],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function findProductBySku(int $businessId, string $sku): bool
    {
        $statement = $this->connection->prepare(
            'SELECT 1 FROM pos_products WHERE business_id = :business_id AND sku = :sku LIMIT 1'
        );
        $statement->execute(['business_id' => $businessId, 'sku' => $sku]);
        return $statement->fetchColumn() !== false;
    }

    public function createProduct(
        int $businessId,
        string $publicId,
        ?int $categoryId,
        string $name,
        ?string $sku,
        string $productType,
        int $basePriceAmount,
        int $taxRateBps,
    ): void {
        $statement = $this->connection->prepare(
            'INSERT INTO pos_products
                (business_id, public_id, category_id, name, sku, product_type, base_price_amount, tax_rate_bps, is_active)
             VALUES
                (:business_id, :public_id, :category_id, :name, :sku, :product_type, :base_price_amount, :tax_rate_bps, 1)'
        );
        $statement->execute([
            'business_id' => $businessId,
            'public_id' => $publicId,
            'category_id' => $categoryId,
            'name' => $name,
            'sku' => $sku,
            'product_type' => $productType,
            'base_price_amount' => $basePriceAmount,
            'tax_rate_bps' => $taxRateBps,
        ]);
    }

    public function findProductIdByPublicId(int $businessId, string $publicId): ?int
    {
        $s=$this->connection->prepare('SELECT id FROM pos_products WHERE business_id=:business_id AND public_id=:public_id AND archived_at IS NULL LIMIT 1');
        $s->execute(['business_id'=>$businessId,'public_id'=>$publicId]); $v=$s->fetchColumn();
        return $v===false?null:(int)$v;
    }

    public function createVariant(int $businessId,int $productId,string $publicId,string $name,?string $sku,int $delta): void
    {
        $s=$this->connection->prepare('INSERT INTO pos_product_variants (business_id,public_id,product_id,name,sku,price_delta_amount,is_active) VALUES (:business_id,:public_id,:product_id,:name,:sku,:delta,1)');
        $s->execute(['business_id'=>$businessId,'public_id'=>$publicId,'product_id'=>$productId,'name'=>$name,'sku'=>$sku,'delta'=>$delta]);
    }

    public function createOptionGroup(int $businessId,string $publicId,string $name,string $type,int $min,int $max,bool $required,array $options): void
    {
        $s=$this->connection->prepare('INSERT INTO pos_option_groups (business_id,public_id,name,selection_type,min_select,max_select,is_required_default,is_active) VALUES (:business_id,:public_id,:name,:type,:min,:max,:required,1)');
        $s->execute(['business_id'=>$businessId,'public_id'=>$publicId,'name'=>$name,'type'=>$type,'min'=>$min,'max'=>$max,'required'=>$required?1:0]);
        $groupId=(int)$this->connection->lastInsertId();
        $insert=$this->connection->prepare('INSERT INTO pos_options (business_id,public_id,group_id,name,price_delta_amount,is_active) VALUES (:business_id,:public_id,:group_id,:name,:delta,1)');
        foreach($options as $option){$insert->execute(['business_id'=>$businessId,'public_id'=>$option['id'],'group_id'=>$groupId,'name'=>$option['name'],'delta'=>$option['price_delta_amount']]);}
    }

    public function findOptionGroupId(int $businessId,string $publicId): ?int
    {
        $s=$this->connection->prepare('SELECT id FROM pos_option_groups WHERE business_id=:business_id AND public_id=:public_id AND is_active=1 LIMIT 1');
        $s->execute(['business_id'=>$businessId,'public_id'=>$publicId]);$v=$s->fetchColumn();
        return $v===false?null:(int)$v;
    }

    public function attachOptionGroup(int $businessId,int $productId,int $groupId,int $sort,bool $required): void
    {
        $s=$this->connection->prepare('INSERT INTO pos_product_option_groups (business_id,product_id,option_group_id,sort_order,is_required) VALUES (:business_id,:product_id,:group_id,:sort,:required)');
        $s->execute(['business_id'=>$businessId,'product_id'=>$productId,'group_id'=>$groupId,'sort'=>$sort,'required'=>$required?1:0]);
    }

    public function productHasOptionGroup(int $businessId,int $productId,int $groupId): bool
    {
        $s=$this->connection->prepare('SELECT 1 FROM pos_product_option_groups WHERE business_id=:business_id AND product_id=:product_id AND option_group_id=:group_id LIMIT 1');
        $s->execute(['business_id'=>$businessId,'product_id'=>$productId,'group_id'=>$groupId]);return $s->fetchColumn()!==false;
    }

    public function productDetails(int $businessId,int $productId, bool $includeInactiveGroups = false): ?array
    {
        $s=$this->connection->prepare('SELECT p.public_id,p.name,p.sku,p.product_type,p.base_price_amount,p.tax_rate_bps,p.is_active,m.public_id AS image_public_id FROM pos_products p LEFT JOIN pos_product_media pm ON pm.product_id=p.id AND pm.business_id=p.business_id LEFT JOIN pos_media_assets m ON m.id=pm.media_id AND m.business_id=pm.business_id WHERE p.business_id=:business_id AND p.id=:product_id AND p.archived_at IS NULL LIMIT 1');
        $s->execute(['business_id'=>$businessId,'product_id'=>$productId]);$row=$s->fetch(PDO::FETCH_ASSOC);
        if(!$row)return null;
        $v=$this->connection->prepare('SELECT public_id,name,sku,price_delta_amount,is_active FROM pos_product_variants WHERE business_id=:business_id AND product_id=:product_id ORDER BY id');
        $v->execute(['business_id'=>$businessId,'product_id'=>$productId]);
        $variants=array_map(static fn(array $x):array=>['id'=>(string)$x['public_id'],'name'=>(string)$x['name'],'sku'=>$x['sku']===null?null:(string)$x['sku'],'price_delta_amount'=>(int)$x['price_delta_amount'],'is_active'=>(bool)$x['is_active']],$v->fetchAll(PDO::FETCH_ASSOC));
        $g=$this->connection->prepare('SELECT g.public_id,g.name,g.selection_type,g.min_select,g.max_select,g.is_required_default,g.is_active AS group_active,pg.sort_order,pg.is_required,o.public_id AS option_public_id,o.name AS option_name,o.price_delta_amount,o.is_active AS option_active FROM pos_product_option_groups pg INNER JOIN pos_option_groups g ON g.id=pg.option_group_id AND g.business_id=pg.business_id LEFT JOIN pos_options o ON o.group_id=g.id AND o.business_id=g.business_id WHERE pg.business_id=:business_id AND pg.product_id=:product_id' . ($includeInactiveGroups ? '' : ' AND g.is_active=1') . ' ORDER BY pg.sort_order,g.id,o.id');
        $g->execute(['business_id'=>$businessId,'product_id'=>$productId]);$groups=[];
        foreach($g->fetchAll(PDO::FETCH_ASSOC) as $x){$id=(string)$x['public_id'];if(!isset($groups[$id]))$groups[$id]=['id'=>$id,'name'=>(string)$x['name'],'selection_type'=>(string)$x['selection_type'],'min_select'=>(int)$x['min_select'],'max_select'=>(int)$x['max_select'],'is_required_default'=>(bool)$x['is_required_default'],'is_active'=>(bool)$x['group_active'],'is_required'=>(bool)$x['is_required'],'sort_order'=>(int)$x['sort_order'],'options'=>[]];if($x['option_public_id']!==null)$groups[$id]['options'][]=['id'=>(string)$x['option_public_id'],'name'=>(string)$x['option_name'],'price_delta_amount'=>(int)$x['price_delta_amount'],'is_active'=>(bool)$x['option_active']];}
        return ['id'=>(string)$row['public_id'],'name'=>(string)$row['name'],'sku'=>$row['sku']===null?null:(string)$row['sku'],'product_type'=>(string)$row['product_type'],'base_price_amount'=>(int)$row['base_price_amount'],'tax_rate_bps'=>(int)$row['tax_rate_bps'],'is_active'=>(bool)$row['is_active'],'image'=>$row['image_public_id']===null?null:['id'=>(string)$row['image_public_id'],'url'=>'/api/v1/media/'.(string)$row['image_public_id']],'variants'=>$variants,'option_groups'=>array_values($groups)];
    }


    public function findCategoryDetails(int $businessId, string $publicId): ?array
    {
        $s = $this->connection->prepare(
            'SELECT c.id, c.public_id, c.parent_id, p.public_id AS parent_public_id, c.name, c.sort_order, c.is_active
             FROM pos_categories c
             LEFT JOIN pos_categories p ON p.id = c.parent_id AND p.business_id = c.business_id
             WHERE c.business_id = :business_id AND c.public_id = :public_id LIMIT 1'
        );
        $s->execute(['business_id' => $businessId, 'public_id' => $publicId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function updateCategory(int $businessId, int $categoryId, string $name, ?int $parentId, int $sortOrder, bool $isActive): void
    {
        $s = $this->connection->prepare(
            'UPDATE pos_categories
             SET name = :name, parent_id = :parent_id, sort_order = :sort_order, is_active = :is_active
             WHERE business_id = :business_id AND id = :id'
        );
        $s->execute([
            'name' => $name, 'parent_id' => $parentId, 'sort_order' => $sortOrder,
            'is_active' => $isActive ? 1 : 0, 'business_id' => $businessId, 'id' => $categoryId,
        ]);
    }

    public function findProductDetails(int $businessId, string $publicId): ?array
    {
        $s = $this->connection->prepare(
            'SELECT p.id, p.public_id, p.category_id, c.public_id AS category_public_id,
                    p.name, p.sku, p.product_type, p.base_price_amount, p.tax_rate_bps, p.is_active, p.archived_at
             FROM pos_products p
             LEFT JOIN pos_categories c ON c.id = p.category_id AND c.business_id = p.business_id
             WHERE p.business_id = :business_id AND p.public_id = :public_id AND p.archived_at IS NULL
             LIMIT 1'
        );
        $s->execute(['business_id' => $businessId, 'public_id' => $publicId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findProductSkuConflict(string $sku, int $exceptProductId): bool
    {
        $s = $this->connection->prepare(
            'SELECT 1 FROM pos_products WHERE sku = :sku AND id <> :except_id LIMIT 1'
        );
        $s->execute(['sku' => $sku, 'except_id' => $exceptProductId]);
        return $s->fetchColumn() !== false;
    }

    public function updateProduct(
        int $businessId, int $productId, ?int $categoryId, string $name, ?string $sku,
        string $productType, int $basePriceAmount, int $taxRateBps, bool $isActive,
    ): void {
        $s = $this->connection->prepare(
            'UPDATE pos_products
             SET category_id = :category_id, name = :name, sku = :sku, product_type = :product_type,
                 base_price_amount = :base_price_amount, tax_rate_bps = :tax_rate_bps, is_active = :is_active
             WHERE business_id = :business_id AND id = :id AND archived_at IS NULL'
        );
        $s->execute([
            'category_id' => $categoryId, 'name' => $name, 'sku' => $sku, 'product_type' => $productType,
            'base_price_amount' => $basePriceAmount, 'tax_rate_bps' => $taxRateBps,
            'is_active' => $isActive ? 1 : 0, 'business_id' => $businessId, 'id' => $productId,
        ]);
    }

    public function archiveProduct(int $businessId, int $productId): void
    {
        $s = $this->connection->prepare(
            'UPDATE pos_products SET is_active = 0, archived_at = CURRENT_TIMESTAMP(3)
             WHERE business_id = :business_id AND id = :id AND archived_at IS NULL'
        );
        $s->execute(['business_id' => $businessId, 'id' => $productId]);
    }


    public function findVariantDetails(int $businessId, string $publicId): ?array
    {
        $s = $this->connection->prepare(
            'SELECT v.id, v.product_id, v.name, v.sku, v.price_delta_amount, v.is_active
             FROM pos_product_variants v
             INNER JOIN pos_products p ON p.id = v.product_id AND p.business_id = v.business_id
             WHERE v.business_id = :business_id AND v.public_id = :public_id AND p.archived_at IS NULL
             LIMIT 1'
        );
        $s->execute(['business_id' => $businessId, 'public_id' => $publicId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function variantSkuExists(int $businessId, int $productId, string $sku, int $exceptVariantId): bool
    {
        $s = $this->connection->prepare(
            'SELECT 1 FROM pos_product_variants
             WHERE business_id = :business_id AND product_id = :product_id
               AND sku = :sku AND id <> :except_id LIMIT 1'
        );
        $s->execute([
            'business_id' => $businessId, 'product_id' => $productId,
            'sku' => $sku, 'except_id' => $exceptVariantId,
        ]);
        return $s->fetchColumn() !== false;
    }

    public function updateVariant(
        int $businessId, int $variantId, string $name, ?string $sku, int $priceDelta, bool $isActive,
    ): void {
        $s = $this->connection->prepare(
            'UPDATE pos_product_variants
             SET name = :name, sku = :sku, price_delta_amount = :price_delta, is_active = :is_active
             WHERE business_id = :business_id AND id = :id'
        );
        $s->execute([
            'name' => $name, 'sku' => $sku, 'price_delta' => $priceDelta,
            'is_active' => $isActive ? 1 : 0, 'business_id' => $businessId, 'id' => $variantId,
        ]);
    }

    public function findOptionGroupDetails(int $businessId, string $publicId): ?array
    {
        $s = $this->connection->prepare(
            'SELECT id, public_id, name, selection_type, min_select, max_select,
                    is_required_default, is_active
             FROM pos_option_groups
             WHERE business_id = :business_id AND public_id = :public_id LIMIT 1'
        );
        $s->execute(['business_id' => $businessId, 'public_id' => $publicId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function countActiveOptions(int $businessId, int $groupId): int
    {
        $s = $this->connection->prepare(
            'SELECT COUNT(*) FROM pos_options
             WHERE business_id = :business_id AND group_id = :group_id AND is_active = 1'
        );
        $s->execute(['business_id' => $businessId, 'group_id' => $groupId]);
        return (int) $s->fetchColumn();
    }

    public function updateOptionGroup(
        int $businessId, int $groupId, string $name, string $selectionType,
        int $minSelect, int $maxSelect, bool $requiredDefault, bool $isActive,
    ): void {
        $s = $this->connection->prepare(
            'UPDATE pos_option_groups
             SET name = :name, selection_type = :selection_type, min_select = :min_select,
                 max_select = :max_select, is_required_default = :required_default, is_active = :is_active
             WHERE business_id = :business_id AND id = :id'
        );
        $s->execute([
            'name' => $name, 'selection_type' => $selectionType, 'min_select' => $minSelect,
            'max_select' => $maxSelect, 'required_default' => $requiredDefault ? 1 : 0,
            'is_active' => $isActive ? 1 : 0, 'business_id' => $businessId, 'id' => $groupId,
        ]);
    }

    public function findOptionDetails(int $businessId, string $publicId): ?array
    {
        $s = $this->connection->prepare(
            'SELECT o.id, o.group_id, o.name, o.price_delta_amount, o.is_active,
                    g.public_id AS group_public_id, g.min_select, g.max_select, g.selection_type
             FROM pos_options o
             INNER JOIN pos_option_groups g ON g.id = o.group_id AND g.business_id = o.business_id
             WHERE o.business_id = :business_id AND o.public_id = :public_id LIMIT 1'
        );
        $s->execute(['business_id' => $businessId, 'public_id' => $publicId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function updateOption(
        int $businessId, int $optionId, string $name, int $priceDelta, bool $isActive,
    ): void {
        $s = $this->connection->prepare(
            'UPDATE pos_options
             SET name = :name, price_delta_amount = :price_delta, is_active = :is_active
             WHERE business_id = :business_id AND id = :id'
        );
        $s->execute([
            'name' => $name, 'price_delta' => $priceDelta, 'is_active' => $isActive ? 1 : 0,
            'business_id' => $businessId, 'id' => $optionId,
        ]);
    }


}
