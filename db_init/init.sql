CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'user',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    price DECIMAL(10, 2) NOT NULL,
    stock_quantity INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Пароль 'user1pass'
INSERT INTO users (name, email, password_hash, role) VALUES
('Іван Франко', 'ivan.franko@example.com', '$2y$10$9jZ.V4cQ8zJY5.KjG6XQveOrqd0wh5HWhjTARq2Sg1q7Uu0v.NOkO', 'user');
-- Пароль 'adminpass'
INSERT INTO users (name, email, password_hash, role) VALUES
('Леся Українка', 'lesya.ukrainka@example.com', '$2y$10$YlC0M2a.w5XnGLvZq6ZzQ.zN5JXR0q87G/qO.G9s2l3tCgG.2w5iS', 'admin');

INSERT INTO products (name, description, price, stock_quantity) VALUES
('Книга "Кобзар"', 'Збірка поетичних творів Тараса Шевченка', 250.00, 50),
('Смартфон Galaxy S25', 'Останнє покоління смартфонів Samsung', 35000.00, 15);