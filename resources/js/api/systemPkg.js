import axios from 'axios';

const api = axios.create({
    baseURL: '/api',
    headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
    },
});

// 添加请求拦截器（如果需要认证token）
api.interceptors.request.use(
    (config) => {
        const token = localStorage.getItem('token');
        if (token) {
            config.headers.Authorization = `Bearer ${token}`;
        }
        return config;
    },
    (error) => {
        return Promise.reject(error);
    }
);

// 打包用酒店管理
export const resHotelsApi = {
    list: (params) => api.get('/res-hotels', { params }),
    create: (data) => api.post('/res-hotels', data),
    get: (id) => api.get(`/res-hotels/${id}`),
    update: (id, data) => api.put(`/res-hotels/${id}`, data),
    delete: (id) => api.delete(`/res-hotels/${id}`),
};

// 打包用房型管理
export const resRoomTypesApi = {
    list: (params) => api.get('/res-room-types', { params }),
    create: (data) => api.post('/res-room-types', data),
    get: (id) => api.get(`/res-room-types/${id}`),
    update: (id, data) => api.put(`/res-room-types/${id}`, data),
    delete: (id) => api.delete(`/res-room-types/${id}`),
};

// 价格库存管理
export const resHotelDailyStocksApi = {
    list: (params) => api.get('/res-hotel-daily-stocks', { params }),
    batchStore: (data) => api.post('/res-hotel-daily-stocks/batch', data),
    update: (id, data) => api.put(`/res-hotel-daily-stocks/${id}`, data),
    delete: (id) => api.delete(`/res-hotel-daily-stocks/${id}`),
};

// 系统打包产品管理
export const salesProductsApi = {
    list: (params) => api.get('/sales-products', { params }),
    create: (data) => api.post('/sales-products', data),
    get: (id) => api.get(`/sales-products/${id}`),
    update: (id, data) => api.put(`/sales-products/${id}`, data),
    delete: (id) => api.delete(`/sales-products/${id}`),
};

// 打包清单管理
export const productBundleItemsApi = {
    list: (params) => api.get('/product-bundle-items', { params }),
    create: (data) => api.post('/product-bundle-items', data),
    update: (id, data) => api.put(`/product-bundle-items/${id}`, data),
    delete: (id) => api.delete(`/product-bundle-items/${id}`),
};

// 价格日历管理
export const salesProductPricesApi = {
    list: (params) => api.get('/sales-product-prices', { params }),
    updateCalendar: (data) => api.post('/sales-product-prices/update-calendar', data),
};

// 系统打包订单管理
export const systemPkgOrdersApi = {
    list: (params) => api.get('/system-pkg-orders', { params }),
    get: (id) => api.get(`/system-pkg-orders/${id}`),
};

// 系统打包异常订单管理
export const systemPkgExceptionOrdersApi = {
    list: (params) => api.get('/system-pkg-exception-orders', { params }),
    get: (id) => api.get(`/system-pkg-exception-orders/${id}`),
    startProcessing: (id) => api.post(`/system-pkg-exception-orders/${id}/start-processing`),
    resolve: (id, data) => api.post(`/system-pkg-exception-orders/${id}/resolve`, data),
};


