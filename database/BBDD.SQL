-- Crear la tabla Campos
CREATE TABLE Campos (
    id VARCHAR(255) PRIMARY KEY,
    nombre VARCHAR(255) NOT NULL,
    tipo_producto_id VARCHAR(255),
    visible BIT NOT NULL,
    obligatorio BIT NOT NULL,
    aparece_formulario BIT NOT NULL
);

-- Crear la tabla Valores
CREATE TABLE Valores (
    campo_id VARCHAR(255),
    valor VARCHAR(255),
    producto_id VARCHAR(255),
    PRIMARY KEY (campo_id, producto_id),
    FOREIGN KEY (campo_id) REFERENCES Campos(id)
);

-- Crear la tabla Tipo_producto
CREATE TABLE Tipo_producto (
    id VARCHAR(255) PRIMARY KEY,
    nombre VARCHAR(255) NOT NULL
);

-- Crear la tabla Sociedad
CREATE TABLE Sociedad (
    id VARCHAR(255) PRIMARY KEY,
    nombre VARCHAR(255) NOT NULL,
    codigo_postal VARCHAR(10),
    poblacion VARCHAR(255),
    tipo_sociedad VARCHAR(255),
    nivel_sociedad INT,
    sociedad_padre_id VARCHAR(255),
    FOREIGN KEY (sociedad_padre_id) REFERENCES Sociedad(id)
);

-- Crear la tabla TipoProducto_Sociedad
CREATE TABLE TipoProducto_Sociedad (
    id VARCHAR(255) PRIMARY KEY,
    id_sociedad VARCHAR(255),
    id_tipo_producto VARCHAR(255),
    FOREIGN KEY (id_sociedad) REFERENCES Sociedad(id),
    FOREIGN KEY (id_tipo_producto) REFERENCES Tipo_producto(id)
);

-- Crear la tabla Comercial
CREATE TABLE Comercial (
    id VARCHAR(255) PRIMARY KEY,
    nombre VARCHAR(255) NOT NULL,
    id_sociedad VARCHAR(255),
    usuario VARCHAR(255) NOT NULL,
    contraseña VARCHAR(255) NOT NULL,
    FOREIGN KEY (id_sociedad) REFERENCES Sociedad(id)
);

-- Crear la tabla Comercial_comision
CREATE TABLE Comercial_comision (
    id_comercial VARCHAR(255),
    porcentual BIT NOT NULL,
    comision DECIMAL(10, 2) NOT NULL,
    FOREIGN KEY (id_comercial) REFERENCES Comercial(id)
);

-- Crear la tabla Tipos_anexos
CREATE TABLE Tipos_anexos (
    id VARCHAR(255) PRIMARY KEY,
    nombre VARCHAR(255) NOT NULL,
    id_familia VARCHAR(255)
);

-- Crear la tabla Campos_anexos
CREATE TABLE Campos_anexos (
    id VARCHAR(255) PRIMARY KEY,
    campo_id VARCHAR(255),
    tipo_anexo VARCHAR(255),
    obligatorio BIT NOT NULL,
    FOREIGN KEY (campo_id) REFERENCES Campos(id),
    FOREIGN KEY (tipo_anexo) REFERENCES Tipos_anexos(id)
);

-- Crear la tabla Valores_anexos
CREATE TABLE Valores_anexos (
    id VARCHAR(255) PRIMARY KEY,
    campo_id VARCHAR(255),
    valor VARCHAR(255) NOT NULL,
    producto VARCHAR(255),
    FOREIGN KEY (campo_id) REFERENCES Campos(id)
);

-- Crear la tabla Tarifas_producto
CREATE TABLE Tarifas_producto (
    id VARCHAR(255) PRIMARY KEY,
    producto VARCHAR(255),
    id_sociedad VARCHAR(255),
    prima_seguro DECIMAL(10, 2),
    cuota_asociacion DECIMAL(10, 2),
    precio_total DECIMAL(10, 2),
    FOREIGN KEY (id_sociedad) REFERENCES Sociedad(id)
);

-- Crear la tabla Tarifas_anexos
CREATE TABLE Tarifas_anexos (
    id VARCHAR(255) PRIMARY KEY,
    anexo VARCHAR(255),
    tiene_escala BIT NOT NULL,
    id_producto VARCHAR(255),
    precio DECIMAL(10, 2),
    FOREIGN KEY (anexo) REFERENCES Tipos_anexos(id),
    FOREIGN KEY (id_producto) REFERENCES Tarifas_producto(id)
);

-- Crear la tabla Escalado_anexo
CREATE TABLE Escalado_anexo (
    anexo_id VARCHAR(255),
    desde DECIMAL(10, 2) NOT NULL,
    hasta DECIMAL(10, 2) NOT NULL,
    precio DECIMAL(10, 2) NOT NULL,
    FOREIGN KEY (anexo_id) REFERENCES Tipos_anexos(id)
);

-- Crear la tabla Navegacion
CREATE TABLE Navegacion (
    id VARCHAR(255) PRIMARY KEY,
    navegacion VARCHAR(255) NOT NULL,
    navegacion_padre_id VARCHAR(255),
    ruta VARCHAR(255),
    nivel INT,
    FOREIGN KEY (navegacion_padre_id) REFERENCES Navegacion(id)
);

-- Crear la tabla CP_familia
CREATE TABLE CP_familia (
    id VARCHAR(255) PRIMARY KEY,
    id_familia VARCHAR(255),
    vetado BIT NOT NULL,
    cp VARCHAR(10),
    FOREIGN KEY (id_familia) REFERENCES Tipos_anexos(id)
);
